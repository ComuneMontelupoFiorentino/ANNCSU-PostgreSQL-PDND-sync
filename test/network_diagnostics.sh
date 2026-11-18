#!/bin/bash
# network_diagnostics.sh
# Verifica che non ci siano interferenze di rete (firewall/proxy/MITM) sulle chiamate
# verso auth.interop.pagopa.it e modipa.agenziaentrate.gov.it

echo "=========================================="
echo "1. Variabili di ambiente proxy"
echo "=========================================="
env | grep -i proxy
echo "(se vuoto, nessun proxy esplicito configurato via env - ok)"
echo ""

echo "=========================================="
echo "2. Orario di sistema vs orario esterno"
echo "=========================================="
echo "Orario locale (UTC): $(date -u)"
EXT_TIME=$(curl -sI https://www.google.com 2>/dev/null | grep -i '^date:' | sed 's/[Dd]ate: //')
echo "Orario esterno (da header HTTP Google): $EXT_TIME"
echo "(le due date devono corrispondere, tolleranza di pochi secondi)"
echo ""

echo "=========================================="
echo "3. Certificato TLS presentato da modipa.agenziaentrate.gov.it"
echo "=========================================="
echo | openssl s_client -connect modipa.agenziaentrate.gov.it:443 -servername modipa.agenziaentrate.gov.it 2>/dev/null | openssl x509 -noout -subject -issuer -dates
echo "(l'Issuer deve essere una CA pubblica riconosciuta - es. DigiCert, Actalis, Aruba, ecc. -"
echo " MAI un nome che richiami firewall aziendali, antivirus, 'proxy', o il nome del vostro comune/fornitore IT)"
echo ""

echo "=========================================="
echo "4. Certificato TLS presentato da auth.interop.pagopa.it"
echo "=========================================="
echo | openssl s_client -connect auth.interop.pagopa.it:443 -servername auth.interop.pagopa.it 2>/dev/null | openssl x509 -noout -subject -issuer -dates
echo ""

echo "=========================================="
echo "5. Test integrità: invio la STESSA identica richiesta a un servizio di echo pubblico"
echo "   per vedere ESATTAMENTE cosa riceve un server esterno da voi"
echo "=========================================="
BODY='{"richiesta":{"codcom":"F551","progr_nazionale":"280066","accesso":{"sezione_censimento":"35","operazione_civico":"I","numero":"31","esponente":"","coordinate":{"x":"11.065912","y":"43.705138","metodo":"3"}}}}'
DIGEST="SHA-256=$(echo -n "$BODY" | openssl dgst -sha256 -binary | openssl base64)"
echo "Digest calcolato localmente: $DIGEST"
echo ""
echo "--- Risposta di postman-echo.com (mostra body e headers ESATTI ricevuti) ---"
curl -s -X POST "https://postman-echo.com/post" \
  -H "Content-Type: application/json" \
  -H "Content-Encoding: identity" \
  -H "Digest: $DIGEST" \
  -d "$BODY" | python3 -m json.tool 2>/dev/null || curl -s -X POST "https://postman-echo.com/post" \
  -H "Content-Type: application/json" \
  -H "Content-Encoding: identity" \
  -H "Digest: $DIGEST" \
  -d "$BODY"
echo ""
echo "CONFRONTA MANUALMENTE:"
echo "- il campo 'data' o 'json' della risposta deve essere IDENTICO byte-per-byte al \$BODY sopra"
echo "- il campo 'headers' deve contenere ESATTAMENTE Digest e Content-Encoding come inviati,"
echo "  senza header aggiuntivi sospetti (es. X-Forwarded-By, Via, X-Proxy-*, nomi di antivirus/firewall aziendali)"
echo ""

echo "=========================================="
echo "6. Traceroute verso l'endpoint (per capire se passa da un proxy aziendale)"
echo "=========================================="
which traceroute >/dev/null 2>&1 && traceroute -m 15 modipa.agenziaentrate.gov.it || echo "traceroute non disponibile, salto questo step"
echo ""

echo "Diagnostica completata."