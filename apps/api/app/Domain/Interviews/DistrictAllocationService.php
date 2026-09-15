<?php

namespace App\Domain\Interviews;

use InvalidArgumentException;

class DistrictAllocationService
{
    /**
     * Greedy longest-processing-time allocation keeps every district intact
     * while balancing whole district candidate counts across centres.
     *
     * @param  list<array{id: string, name: string, applications: list<array{id: string, reference: string}>}>  $districts
     * @param  list<array{id: string, name: string, panels: list<array{id: string, session_id: string, session_date: string, reporting_time: string, capacity: int, existing_load: int}>}>  $centres
     * @return array{districts: list<array<string, mixed>>, centres: list<array<string, mixed>>, assignments: list<array<string, mixed>>}
     */
    public function allocate(array $districts, array $centres): array
    {
        if ($districts === []) {
            throw new InvalidArgumentException('There are no validated candidates in this region to allocate.');
        }
        if ($centres === []) {
            throw new InvalidArgumentException('This region has no scheduled centre sessions with open panels.');
        }

        $centreState = [];
        foreach ($centres as $centre) {
            if ($centre['panels'] === []) {
                continue;
            }
            $panels = [];
            foreach ($centre['panels'] as $panel) {
                $panels[$panel['id']] = [...$panel, 'load' => $panel['existing_load']];
            }
            $centreState[$centre['id']] = [
                'id' => $centre['id'],
                'name' => $centre['name'],
                'load' => array_sum(array_column($centre['panels'], 'existing_load')),
                'capacity' => array_sum(array_column($centre['panels'], 'capacity')),
                'panels' => $panels,
            ];
        }
        if ($centreState === []) {
            throw new InvalidArgumentException('This region has no usable interview panel capacity.');
        }

        usort($districts, fn (array $left, array $right): int => count($right['applications']) <=> count($left['applications'])
            ?: strcmp($left['name'], $right['name'])
            ?: strcmp($left['id'], $right['id']));
        $districtResults = [];
        $assignments = [];
        foreach ($districts as $district) {
            $candidateCount = count($district['applications']);
            $eligibleCentres = array_filter(
                $centreState,
                fn (array $centre): bool => ($centre['capacity'] - $centre['load']) >= $candidateCount,
            );
            if ($eligibleCentres === []) {
                throw new InvalidArgumentException("No centre has enough remaining panel capacity for {$district['name']} without splitting its candidates.");
            }
            usort($eligibleCentres, fn (array $left, array $right): int => $left['load'] <=> $right['load']
                ?: strcmp($left['name'], $right['name'])
                ?: strcmp($left['id'], $right['id']));
            $selectedCentreId = $eligibleCentres[0]['id'];

            usort($district['applications'], fn (array $left, array $right): int => strcmp($left['reference'], $right['reference']) ?: strcmp($left['id'], $right['id']));
            foreach ($district['applications'] as $application) {
                $availablePanels = array_filter(
                    $centreState[$selectedCentreId]['panels'],
                    fn (array $panel): bool => $panel['load'] < $panel['capacity'],
                );
                usort($availablePanels, fn (array $left, array $right): int => $left['load'] <=> $right['load']
                    ?: strcmp($left['session_date'], $right['session_date'])
                    ?: strcmp($left['reporting_time'], $right['reporting_time'])
                    ?: strcmp($left['id'], $right['id']));
                $panel = $availablePanels[0] ?? null;
                if ($panel === null) {
                    throw new InvalidArgumentException("Interview panel capacity was exhausted while assigning {$district['name']}.");
                }
                $assignmentOrder = $panel['load'] + 1;
                $centreState[$selectedCentreId]['panels'][$panel['id']]['load'] = $assignmentOrder;
                $centreState[$selectedCentreId]['load']++;
                $assignments[] = [
                    'application_id' => $application['id'],
                    'application_reference' => $application['reference'],
                    'district_id' => $district['id'],
                    'district_name' => $district['name'],
                    'recruitment_centre_id' => $selectedCentreId,
                    'centre_name' => $centreState[$selectedCentreId]['name'],
                    'centre_session_id' => $panel['session_id'],
                    'panel_id' => $panel['id'],
                    'assignment_order' => $assignmentOrder,
                    'scheduled_date' => $panel['session_date'],
                    'reporting_time' => $panel['reporting_time'],
                ];
            }
            $districtResults[] = [
                'district_id' => $district['id'],
                'district_name' => $district['name'],
                'recruitment_centre_id' => $selectedCentreId,
                'centre_name' => $centreState[$selectedCentreId]['name'],
                'candidate_count' => $candidateCount,
                'centre_load_after_assignment' => $centreState[$selectedCentreId]['load'],
            ];
        }

        $centreResults = array_values(array_map(fn (array $centre): array => [
            'recruitment_centre_id' => $centre['id'],
            'centre_name' => $centre['name'],
            'candidate_count' => $centre['load'] - array_sum(array_column($centre['panels'], 'existing_load')),
            'total_load' => $centre['load'],
            'capacity' => $centre['capacity'],
        ], $centreState));
        usort($centreResults, fn (array $left, array $right): int => strcmp($left['centre_name'], $right['centre_name']));

        return ['districts' => $districtResults, 'centres' => $centreResults, 'assignments' => $assignments];
    }
}
