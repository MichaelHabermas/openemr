Here’s a clear breakdown based on the PDF (pages 2–5):

### 1. Can you use the uploader + extra code to handle PDFs with multimodal LLMs (including charts/tables)?

**Yes — this is explicitly encouraged and required.**

- The spec says you must **“store the source document in OpenEMR”** (page 4). That’s precisely what the built-in OpenEMR document uploader / lab report uploader already does.
- Then you add an **extra code layer** (`attach_and_extract` tool / intake-extractor worker) that:
  - Takes the uploaded PDF (lab PDF or intake form).
  - Uses a **VLM (Vision Language Model = multimodal LLM)** to extract structured facts.
- The “Hard Problems” section (page 2) literally calls this out:  
  > “**Vision extraction without invention** — A VLM can read a scanned form…”

Lab PDFs frequently contain tables, charts, graphs, etc., so a multimodal model (e.g. GPT-4o, Claude-3, ColQwen2, etc.) is the expected way to “see” and infer that information reliably.

**Important nuance**:  
The project does **NOT** want you to “just vectorize” the patient PDFs and throw them into RAG for loose inference.  

- Vector/hybrid RAG is **only** for the small **clinical-guideline corpus** (Stage 2, Core Requirement 3).  
- Patient documents (lab PDF + intake form) must go through **strict structured extraction** → Pydantic/Zod schema → JSON, not raw vector search.

You still get the benefits of multimodal (charts/tables, imperfect scans, etc.), but the output must be clean, schema-validated, and citation-linked.

### 2. Does the spec require you to extract the data from the PDF and **actually input it into the lab report tables** in OpenEMR?

**Yes — 100% required (this is one of the core deliverables).**

Direct quotes from the PDF:

- Page 4, Core Requirement 1:  
  > “It must store the source document in OpenEMR, return strict-schema JSON, **and persist derived facts as appropriate FHIR resources or OpenEMR records**.”

- Required lab schema fields (page 4):  
  > test name, value, unit, reference range, collection date, abnormal flag, and source citation.

- Page 5 (Core/Extension):  
  > “Lab trend chart widget that uses extracted Observation data.”

- Earlier section (FHIR/OpenEMR integrity):  
  > “Uploaded documents and **derived observations** must round-trip through OpenEMR without creating duplicate or untraceable records.”

So the flow the spec demands is:

1. Upload PDF via OpenEMR’s normal document/lab uploader.
2. Your `intake-extractor` worker (multimodal LLM) reads it.
3. Extracts structured JSON matching the lab schema.
4. **Persists** those facts back into OpenEMR as FHIR Observation resources or native lab result records.
5. Every fact stays linked to the original PDF page/section (with visual bounding-box overlay required).

This is **not optional** — it’s part of the MVP and one of the hard gates.

### Quick summary for your implementation

| Part | What the spec wants | How to achieve it |
|------|---------------------|-------------------|
| Upload & store PDF | Use existing OpenEMR uploader | Already there |
| Extract from charts/tables | Multimodal VLM + strict schema | Extra code (intake-extractor worker) |
| Persist into lab tables | Save as FHIR/OpenEMR records | Required |
| RAG | Only on guideline corpus | Separate hybrid RAG (not on patient PDFs) |
| Citations | Machine-readable + visual bounding-box | VLM must support region highlighting |

If you implement the uploader → VLM extraction → structured persist flow, you’ll be directly on-spec for the Week 2 MVP. Let me know if you want help sketching the schemas, the worker graph, or the exact `attach_and_extract` tool signature.
