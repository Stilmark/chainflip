# Chainflip LP Strategy – V3 Notes

## Status
Follow-up to **v2 proposal (90-day analysis)**  
Reflects validation against recent live trade data (post S1–S3 implementation)

---

## Summary

The v2 proposal introduced several important structural ideas about pool behavior and LP positioning.  
Recent live data confirms some of these ideas, while others require adjustment.

This document captures the **delta between v2 theory and current reality**.

---

## What Held Up (Validated)

### 1. C as Efficiency Driver
- Tighter, edge-focused positioning (C rung) showed strong fee efficiency.
- Higher return per unit of capital compared to wider ranges.

**Conclusion:**  
Maintain and potentially prioritize tight “edge” ranges.

---

### 2. D as Tail / Signal Layer
- Very low fee contribution relative to capital.
- High coverage during out-of-range events.

**Conclusion:**  
D should remain:
- small
- defensive
- non-primary for yield

---

## What Did Not Hold

### 3. A/B Narrowing Was Too Aggressive
- v2 proposed trimming upper range exposure.
- Recent trades show meaningful fee generation in those excluded zones.

**Observed Impact:**
- Significant portion of realized fees would have been missed.

**Conclusion:**  
Maintain broader coverage for A and B than proposed in v2.

---

## What Changed (Post-v2 Evolution)

### 4. Introduction of S1–S3 (Scalp Layer)
- Not included in v2 analysis.
- Captures micro-movements near peg.
- Low utilization, but high efficiency when active.

**Conclusion:**  
Scalp layer is now a **core structural component** of the strategy.

---

## Updated Strategic Interpretation

The current system behaves as a **layered LP structure**:

- **S1–S3:** ultra-tight, high-efficiency micro capture
- **C:** tight edge efficiency
- **A:** core fee engine
- **B:** stability + consistent participation
- **D:** tail protection

---

## Key Takeaways

1. Do not over-optimize based on historical density alone  
2. Maintain some excess coverage to capture unexpected flow  
3. Tight ranges improve efficiency, but require broader support layers  
4. Strategy must evolve with live behavior, not just historical analysis  

---

## Usage Guidance

- Treat v2 as a **hypothesis framework**
- Treat this document as a **validation layer**
- Always prioritize **current trade data over historical assumptions**

---

## Status Going Forward

- v2: Reference / Historical  
- v3: Current interpretation (subject to continuous validation)

