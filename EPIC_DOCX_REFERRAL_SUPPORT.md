# Epic 5: DOCX Referral Support

## Status

Implemented and verified.

## Implementation Notes

- Preserve existing Epic 4 TIFF changes.
- Add bounded runtime support for `referral_docx`.
- Keep XLSX and HL7 v2 contract-only.
- Keep referral facts as cited document facts only.
- Do not add DOCX dependencies; use native `ZipArchive` plus XML parsing.

## Verification

- Focused AgentForge document/content tests passed.
- Clinical-document eval gate passed.
- Comprehensive AgentForge check passed.
