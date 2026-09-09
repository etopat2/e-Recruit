import { describe, expect, it } from 'vitest'
import {
  educationLevelFor,
  educationLevelGroups,
  resultOptionsForEducationLevel,
} from './educationQualifications'

describe('Ugandan education qualification catalogue', () => {
  it('covers school, higher education, and technical or vocational pathways', () => {
    expect(educationLevelGroups.map((group) => group.label)).toEqual([
      'School education',
      'University and other higher education',
      'Technical, vocational, tertiary, and institute awards',
    ])
    expect(educationLevelFor('PLE')?.label).toContain('National Level 1')
    expect(educationLevelFor("Bachelor's Degree")?.label).toContain('UHEQF Level 7')
    expect(educationLevelFor('Doctorate (PhD)')?.label).toContain('UHEQF Level 9')
    expect(educationLevelFor('National Certificate (TVET)')).toBeDefined()
    expect(educationLevelFor('UVQF Level 4 Diploma')).toBeDefined()
  })

  it('returns results that are specific to the selected education level', () => {
    expect(resultOptionsForEducationLevel('PLE').map((option) => option.value)).toContain('Division 1')
    expect(resultOptionsForEducationLevel('UCE').map((option) => option.value)).toContain('Result 1 (Certificate awarded)')
    expect(resultOptionsForEducationLevel('UACE').map((option) => option.value)).toEqual([
      '3 Principal passes (3P)',
      '2 Principal passes (2P)',
      '1 Principal pass (1P)',
      '1 Subsidiary pass (1S)',
      'Fail (F)',
    ])
    expect(resultOptionsForEducationLevel("Bachelor's Degree").map((option) => option.value)).toContain('First Class (CGPA 4.4–5.0)')
    expect(resultOptionsForEducationLevel('UVQF Level 1 Certificate').map((option) => option.value)).toContain('Partial qualification (modular transcript)')
    expect(resultOptionsForEducationLevel('Unknown qualification')).toEqual([])
  })
})
