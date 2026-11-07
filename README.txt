PROGETTI DIMOSTRATIVI WORDPRESS - Alessandro Fior
=================================================
Installazione: Plugin > Aggiungi nuovo > Carica plugin (un .zip per volta), poi Attiva.
Requisiti: WordPress 6.x, PHP 7.4+.

1) AF Catalogo Documenti (af-catalogo-documenti.zip)
   - Custom post type "Documento" + tassonomia "Categorie documento"
   - Campi personalizzati (codice, versione, URL fonte) con nonce e controllo permessi
   - Import/Export CSV da Documenti > Import / Export (upsert per codice)
   - REST API: GET /wp-json/af-catalogo/v1/export, POST /wp-json/af-catalogo/v1/import (richiede login)
   - Sincronizzazione giornaliera (WP-Cron) da un file JSON esterno

2) AF AI Assistant (af-ai-assistant.zip)
   - Meta box "Assistente AI" in articoli e pagine: riassunto, categoria suggerita e tag
   - Chiave API in wp-config.php:  define( 'AF_AI_API_KEY', '...' );  (oppure da Impostazioni)
   - Chiamata server-side all'API Anthropic; chiave mai esposta al browser
   - Gestione errori, timeout, limite caratteri inviati, output sanificato

Possibili estensioni: logging delle chiamate, coda asincrona, ricerca semantica (RAG) sui documenti del catalogo.
