# Test intermedi per valutare funzioanmento dello script


## Elenco dei file di test
- test_single_record.php: Esegue UNA SOLA chiamata reale verso l'e-service (produzione o test, a seconda di --prod/--test), usando UN SOLO record estratto da tabella_operazioni;
- test_voucher.php: Script isolato per testare SOLO il recupero del voucher PDND (nessuna chiamata all'e-service, nessuna scrittura/lettura da ANNCSU);


## Funzionamento

Spostare i file al livello di anncsu.php ed eseguirli