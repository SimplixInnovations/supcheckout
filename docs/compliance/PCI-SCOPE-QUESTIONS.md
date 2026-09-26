# PCI Scope Questions (for QSA/acquirer)

1. Does provider-hosted payment page fully isolate PAN/CVV from merchant environment?
2. Are stored provider tokens in-scope cardholder data or tokens under a tokenization exemption?
3. What PCI SAQ type applies to this integration? (do not assign as fact)
4. Are order-meta token fields in CDE?
5. What encryption-at-rest is required for stored tokens?
6. Do admin order screens display token values?
7. Are logs/backups free of sensitive authentication data?
8. What is the incident-response obligation if tokens leak?

```text
PCI: EXTERNAL ORGANIZATIONAL/ACQUIRER/QSA REVIEW REQUIRED
```
