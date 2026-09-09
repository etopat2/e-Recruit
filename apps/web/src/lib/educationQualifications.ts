export interface EducationSelectOption {
  value: string
  label: string
}

/**
 * Qualification levels follow UNEB national awards, the NCHE Uganda Higher
 * Education Qualifications Framework, and current/legacy MoES TVET pathways.
 * Result values deliberately mirror wording applicants can verify on awards.
 */

export interface EducationLevelOption extends EducationSelectOption {
  guidance: string
  results: readonly EducationSelectOption[]
}

export interface EducationLevelGroup {
  label: string
  options: readonly EducationLevelOption[]
}

const pleResults: readonly EducationSelectOption[] = [
  { value: 'Division 1', label: 'Division 1' },
  { value: 'Division 2', label: 'Division 2' },
  { value: 'Division 3', label: 'Division 3' },
  { value: 'Division 4', label: 'Division 4' },
  { value: 'Ungraded (Division U)', label: 'Ungraded (Division U)' },
  { value: 'Absent / incomplete (Division X)', label: 'Absent / incomplete (Division X)' },
]

const uceResults: readonly EducationSelectOption[] = [
  { value: 'Result 1 (Certificate awarded)', label: 'Result 1 — certificate awarded (current curriculum)' },
  { value: 'Result 2 (Certificate conditions not fulfilled)', label: 'Result 2 — certificate conditions not fulfilled (current)' },
  { value: 'Result 3 (Minimum achievement not met)', label: 'Result 3 — minimum achievement not met (current)' },
  { value: 'Result 4 (Partially or fully absent)', label: 'Result 4 — partially or fully absent (current)' },
  { value: 'Division 1', label: 'Division 1 (legacy curriculum)' },
  { value: 'Division 2', label: 'Division 2 (legacy curriculum)' },
  { value: 'Division 3', label: 'Division 3 (legacy curriculum)' },
  { value: 'Division 4', label: 'Division 4 (legacy curriculum)' },
  { value: 'Result 7 (Certificate not awarded)', label: 'Result 7 — certificate not awarded (legacy entry rules)' },
  { value: 'Ungraded (Division U)', label: 'Ungraded / Division U (legacy curriculum)' },
  { value: 'Absent / incomplete (Division X)', label: 'Absent / incomplete / Division X (legacy)' },
]

const uaceResults: readonly EducationSelectOption[] = [
  { value: '3 Principal passes (3P)', label: '3 Principal-level passes (3P)' },
  { value: '2 Principal passes (2P)', label: '2 Principal-level passes (2P)' },
  { value: '1 Principal pass (1P)', label: '1 Principal-level pass (1P)' },
  { value: '1 Subsidiary pass (1S)', label: '1 Subsidiary-level pass (1S)' },
  { value: 'Fail (F)', label: 'Fail (F)' },
]

const ncheClassifications: readonly EducationSelectOption[] = [
  { value: 'First Class (CGPA 4.4–5.0)', label: 'First Class — CGPA 4.4–5.0' },
  { value: 'Second Class Upper Division (CGPA 4.0–4.3)', label: 'Second Class, Upper Division — CGPA 4.0–4.3' },
  { value: 'Second Class Lower Division (CGPA 3.0–3.9)', label: 'Second Class, Lower Division — CGPA 3.0–3.9' },
  { value: 'Pass (CGPA 2.0–2.9)', label: 'Pass — CGPA 2.0–2.9' },
  { value: 'Fail / not awarded (CGPA 0–1.9)', label: 'Fail / not awarded — CGPA 0–1.9' },
  { value: 'Awarded (not classified)', label: 'Awarded — qualification not classified' },
]

const postgraduateClassifications: readonly EducationSelectOption[] = [
  { value: 'Distinction', label: 'Distinction' },
  { value: 'Merit / Credit', label: 'Merit / Credit' },
  { value: 'Pass', label: 'Pass' },
  { value: 'Awarded (not classified)', label: 'Awarded — qualification not classified' },
  { value: 'Fail / not awarded', label: 'Fail / not awarded' },
]

const doctorateResults: readonly EducationSelectOption[] = [
  { value: 'Degree awarded (not classified)', label: 'Degree awarded — not classified' },
  { value: 'Fail / not awarded', label: 'Not awarded' },
]

const tvetClassifications: readonly EducationSelectOption[] = [
  { value: 'Class I (Distinction)', label: 'Class I — Distinction' },
  { value: 'Class II (Credit)', label: 'Class II — Credit' },
  { value: 'Class III (Pass)', label: 'Class III — Pass' },
  { value: 'Conceded Pass (CP)', label: 'Conceded Pass (CP)' },
  { value: 'Competence Class Certificate (partial modules)', label: 'Competence Class Certificate — partial modules' },
  { value: 'Awarded (not classified)', label: 'Awarded — qualification not classified' },
  { value: 'Fail / not awarded', label: 'Fail / not awarded' },
]

const uvqfResults: readonly EducationSelectOption[] = [
  { value: 'Full qualification awarded', label: 'Full occupational qualification awarded' },
  { value: 'Partial qualification (modular transcript)', label: 'Partial qualification — modular transcript' },
  { value: 'Not yet competent / not awarded', label: 'Not yet competent / not awarded' },
]

const workersPasResults: readonly EducationSelectOption[] = [
  { value: 'Full occupation certified', label: 'Full occupation certified' },
  { value: 'Partial modules certified', label: 'Partial occupational modules certified' },
  { value: 'Not yet certified', label: 'Not yet certified' },
]

const legacyTradeResults: readonly EducationSelectOption[] = [
  { value: 'Awarded / passed', label: 'Awarded / passed' },
  { value: 'Fail / not awarded', label: 'Fail / not awarded' },
]

export const educationLevelGroups: readonly EducationLevelGroup[] = [
  {
    label: 'School education',
    options: [
      {
        value: 'PLE',
        label: 'Primary Leaving Examination (PLE) — National Level 1',
        guidance: 'Choose the overall PLE division printed on the result slip or certificate.',
        results: pleResults,
      },
      {
        value: 'UCE',
        label: 'Uganda Certificate of Education (UCE / O-Level) — National Level 2',
        guidance: 'For the competency-based curriculum, choose overall Result 1–4. A–E achievement levels are subject grades, not an overall class. Legacy UCE divisions remain available.',
        results: uceResults,
      },
      {
        value: 'UACE',
        label: 'Uganda Advanced Certificate of Education (UACE / A-Level) — National Level 3',
        guidance: 'Choose the overall UNEB pass level: principal passes, subsidiary pass, or fail.',
        results: uaceResults,
      },
    ],
  },
  {
    label: 'University and other higher education',
    options: [
      {
        value: 'Higher Education Certificate',
        label: 'Higher Education Certificate / Foundation — UHEQF Level 4',
        guidance: 'Choose the final classification exactly as printed by the awarding institution.',
        results: ncheClassifications,
      },
      {
        value: 'Diploma',
        label: 'Ordinary Diploma — UHEQF Level 5',
        guidance: 'Choose the final classification exactly as printed by the awarding institution.',
        results: ncheClassifications,
      },
      {
        value: 'Higher Diploma',
        label: 'Advanced / Higher Diploma — UHEQF Level 6',
        guidance: 'Choose the final classification exactly as printed by the awarding institution.',
        results: ncheClassifications,
      },
      {
        value: "Bachelor's Degree",
        label: 'Bachelor’s Degree — UHEQF Level 7',
        guidance: 'Use the final award classification on the transcript or certificate.',
        results: ncheClassifications,
      },
      {
        value: 'Postgraduate Certificate',
        label: 'Postgraduate Certificate — UHEQF Level 8',
        guidance: 'Postgraduate wording varies by institution; choose the classification printed on the award.',
        results: postgraduateClassifications,
      },
      {
        value: 'Postgraduate Diploma',
        label: 'Postgraduate Diploma — UHEQF Level 8',
        guidance: 'Postgraduate wording varies by institution; choose the classification printed on the award.',
        results: postgraduateClassifications,
      },
      {
        value: "Master's Degree",
        label: 'Master’s Degree — UHEQF Level 8',
        guidance: 'Master’s awards may be classified or unclassified; match the wording on the official award.',
        results: postgraduateClassifications,
      },
      {
        value: 'Doctorate (PhD)',
        label: 'Doctoral Degree / PhD — UHEQF Level 9',
        guidance: 'Doctoral degrees are normally awarded without a class.',
        results: doctorateResults,
      },
    ],
  },
  {
    label: 'Technical, vocational, tertiary, and institute awards',
    options: [
      {
        value: 'Uganda Community Polytechnic Certificate',
        label: 'Uganda Community Polytechnic Certificate',
        guidance: 'Choose the final class or award status printed on the certificate or transcript.',
        results: tvetClassifications,
      },
      {
        value: 'National Certificate (TVET)',
        label: 'National Certificate (TVET)',
        guidance: 'Choose the final class or award status printed on the certificate or transcript.',
        results: tvetClassifications,
      },
      {
        value: 'National Craftsperson Certificate (TVET)',
        label: 'National Craftsperson Certificate (TVET)',
        guidance: 'Choose the final class or competence result printed on the award.',
        results: tvetClassifications,
      },
      {
        value: 'National Diploma (TVET)',
        label: 'National / Technician Diploma (TVET)',
        guidance: 'Choose Class I, II, or III when shown, or the exact award status on the transcript.',
        results: tvetClassifications,
      },
      {
        value: 'Higher National Diploma (TVET)',
        label: 'Higher National Diploma (TVET)',
        guidance: 'Choose the final class or award status printed on the certificate or transcript.',
        results: tvetClassifications,
      },
      {
        value: "Bachelor's Degree (TVET)",
        label: 'Bachelor’s Degree (TVET)',
        guidance: 'Use the final award classification on the transcript or certificate.',
        results: ncheClassifications,
      },
      {
        value: 'Informal Skills Certificate (TVET)',
        label: 'Informal Skills Certificate (TVET)',
        guidance: 'Choose the competence or certification outcome printed on the award.',
        results: uvqfResults,
      },
      {
        value: 'UVQF Basic / Modular Award',
        label: 'UVQF Basic / Modular Award',
        guidance: 'UVQF modular learning may be recorded as a partial qualification on a transcript.',
        results: uvqfResults,
      },
      {
        value: 'UVQF Level 1 Certificate',
        label: 'UVQF Level 1 Certificate — supervised worker',
        guidance: 'Choose whether the full occupational qualification or only modular units were awarded.',
        results: uvqfResults,
      },
      {
        value: 'UVQF Level 2 Certificate',
        label: 'UVQF Level 2 Certificate — moderate supervision',
        guidance: 'Choose whether the full occupational qualification or only modular units were awarded.',
        results: uvqfResults,
      },
      {
        value: 'UVQF Level 3 Certificate',
        label: 'UVQF Level 3 Certificate — supervisory competence',
        guidance: 'Choose whether the full occupational qualification or only modular units were awarded.',
        results: uvqfResults,
      },
      {
        value: 'UVQF Level 4 Diploma',
        label: 'UVQF Level 4 Diploma — technician competence',
        guidance: 'Choose whether the full occupational qualification or only modular units were awarded.',
        results: uvqfResults,
      },
      {
        value: "Worker'sPAS",
        label: 'Worker’sPAS — formally recognised workplace skills',
        guidance: 'Choose whether the full occupation or only specific modules were certified.',
        results: workersPasResults,
      },
      {
        value: 'Craft Certificate (legacy TVET)',
        label: 'Craft Certificate (legacy TVET award)',
        guidance: 'Choose the final class printed on the historical award.',
        results: tvetClassifications,
      },
      {
        value: 'Advanced Craft Certificate (legacy TVET)',
        label: 'Advanced Craft Certificate (legacy TVET award)',
        guidance: 'Choose the final class printed on the historical award.',
        results: tvetClassifications,
      },
      {
        value: 'Trade Test Grade II (legacy)',
        label: 'Trade Test Grade II — legacy award (replaced by UVQF Level 1)',
        guidance: 'Use this only when it is the title printed on a historical certificate.',
        results: legacyTradeResults,
      },
      {
        value: 'Trade Test Grade I (legacy)',
        label: 'Trade Test Grade I — legacy award (replaced by UVQF Level 2)',
        guidance: 'Use this only when it is the title printed on a historical certificate.',
        results: legacyTradeResults,
      },
      {
        value: 'Trade Test Master Craft (legacy)',
        label: 'Trade Test Master Craft — legacy award (replaced by UVQF Level 3)',
        guidance: 'Use this only when it is the title printed on a historical certificate.',
        results: legacyTradeResults,
      },
      {
        value: 'Certificate in Vocational Training Instruction (CVTI)',
        label: 'Certificate in Vocational Training Instruction (CVTI)',
        guidance: 'Choose the class or award status printed on the certificate.',
        results: tvetClassifications,
      },
      {
        value: 'Diploma in Vocational Training Instruction (DVTI)',
        label: 'Diploma in Vocational Training Instruction (DVTI)',
        guidance: 'Choose the class or award status printed on the diploma.',
        results: tvetClassifications,
      },
      {
        value: 'Diploma in Training Institution Management (DTIM)',
        label: 'Diploma in Training Institution Management (DTIM)',
        guidance: 'Choose the class or award status printed on the diploma.',
        results: tvetClassifications,
      },
    ],
  },
]

const educationLevels = educationLevelGroups.flatMap((group) => group.options)
const educationLevelIndex = new Map(educationLevels.map((level) => [level.value, level]))

export function educationLevelFor(value: string): EducationLevelOption | undefined {
  return educationLevelIndex.get(value)
}

export function resultOptionsForEducationLevel(value: string): readonly EducationSelectOption[] {
  return educationLevelFor(value)?.results ?? []
}

export function isKnownEducationLevel(value: string): boolean {
  return educationLevelIndex.has(value)
}

export function isKnownEducationResult(level: string, result: string): boolean {
  return resultOptionsForEducationLevel(level).some((option) => option.value === result)
}
