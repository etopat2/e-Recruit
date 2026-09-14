import { api } from './api'

export interface EducationSelectOption {
  value: string
  label: string
}

export interface EducationLevelOption extends EducationSelectOption {
  guidance: string
  results: readonly EducationSelectOption[]
  directory_searchable: boolean
}

export interface EducationLevelGroup {
  label: string
  options: readonly EducationLevelOption[]
}

let cataloguePromise: Promise<readonly EducationLevelGroup[]> | null = null

export function loadEducationLevelGroups(): Promise<readonly EducationLevelGroup[]> {
  cataloguePromise ??= api<{ data: EducationLevelGroup[] }>('/education-qualification-levels', { cacheTtlMs: 3_600_000 })
    .then((response) => response.data)
    .catch((error: unknown) => {
      cataloguePromise = null
      throw error
    })

  return cataloguePromise
}

export function clearEducationCatalogueCache(): void {
  cataloguePromise = null
}

export function educationLevelFor(groups: readonly EducationLevelGroup[], value: string): EducationLevelOption | undefined {
  return groups.flatMap((group) => group.options).find((level) => level.value === value)
}

export function resultOptionsForEducationLevel(groups: readonly EducationLevelGroup[], value: string): readonly EducationSelectOption[] {
  return educationLevelFor(groups, value)?.results ?? []
}

export function isKnownEducationLevel(groups: readonly EducationLevelGroup[], value: string): boolean {
  return educationLevelFor(groups, value) !== undefined
}

export function isKnownEducationResult(groups: readonly EducationLevelGroup[], level: string, result: string): boolean {
  return resultOptionsForEducationLevel(groups, level).some((option) => option.value === result)
}
