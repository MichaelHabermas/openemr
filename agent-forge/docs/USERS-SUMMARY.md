# Users and Use Cases Summary

## Primary User

- **Role:** Employed primary care physician in a multi-provider OpenEMR clinic.
- **Environment:** Desktop-first workflow, high visit volume, short chart-open decision windows.
- **Patient mix:** Geriatric-leaning, multi-morbid, polypharmacy-heavy panel.

## Agent Surface in Workflow

- **When it appears:** At chart-open for each patient visit.
- **How it works:**
  - **Surface A:** Pre-computed, row-cited summary card for immediate scan.
  - **Surface B:** Optional multi-turn chat for drill-down follow-up questions.
- **Hard boundary:** No diagnosis, medication recommendations, dose changes, or causal claims.

## Core Use Cases

1. **Pre-visit "what changed" briefing**
   - Summarizes last plan, new events, key trends, and medication flags.
2. **Polypharmacy interaction and duplication flagging**
   - Shows interaction/duplication flags with rule-level citations only.
3. **Trend drill-down for labs and vitals**
   - Supports iterative pivots (time ranges, comparisons, focused trends).
4. **"Last 30 days changes" snapshot**
   - Lists explicit timestamped changes for meds, labs, and problems.

## Quality Standard

- **Required:** Every claim must be chart-grounded and source-traceable.
- **Allowed:** Clearly labeled under-coverage.
- **Not allowed:** Any unsourced or inferential clinical claim.
