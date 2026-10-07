# Manuale d'uso — Fairy Bus

## Introduzione <!-- route: * -->

Fairy Bus è il gestionale interno per l'officina e il magazzino del deposito
autobus. Copre anagrafiche (clienti, fornitori), parco automezzi, magazzino
ricambi, documenti (carichi, scarichi, fatture), manutenzioni, scadenze,
rifornimenti e calendario, con stampa PDF integrata.

![La Dashboard: indicatori, grafici e scadenze a colpo d'occhio](img/dashboard.png)

### Convenzioni dell'interfaccia

- **Menu laterale**: la navigazione è organizzata in sezioni (Dashboard,
  Anagrafiche, Officina, Documenti, Rifornimenti, Magazzino, Impostazioni).
  Le sezioni si espandono con un clic.
- **Tabelle**: ogni elenco ha un campo di ricerca in alto che filtra le righe
  mentre digiti; molte colonne hanno filtri a tendina. Le tabelle si possono
  ordinare cliccando sull'intestazione della colonna.
- **Schede (dialog)**: creazione e modifica avvengono in schede a comparsa.
  Le schede con più sezioni hanno tab in alto (es. la manutenzione ha
  Informazioni / Ricambi / Fatture).
- **Ricerca articoli**: nei movimenti di magazzino e nei ricambi il campo
  articolo è una tabella ricercabile: digita il codice, un alias o parte del
  nome. Se cerchi un codice alias vengono mostrati anche il prodotto
  originale e gli altri alias. La colonna Giacenza è colorata: verde se
  disponibile, gialla se zero, rossa se negativa.
- **Selezione rapida**: quando clicchi in una casella di testo o numerica
  tutto il contenuto viene selezionato automaticamente: digita per
  sostituirlo.
- **Notifiche**: ogni operazione conferma o segnala errori con un messaggio
  (toast). Posizione e durata sono configurabili in Impostazioni →
  Interfaccia.
- **Stampa PDF**: molte pagine hanno il pulsante Stampa che genera un
  documento PDF delle righe selezionate o del record aperto.
- **Manuale**: il pulsante "?" in alto a destra apre questo manuale alla
  sezione della pagina che stai usando.

### Come funziona il magazzino

- I **Carichi** aumentano la giacenza dei prodotti; gli **Scarichi** e i
  **ricambi usati in manutenzione** la diminuiscono.
- Eliminando un movimento la quantità viene stornata automaticamente
  (il magazzino si ricarica per gli scarichi e si scarica per i carichi).
- Le righe importate dal vecchio gestionale sono marcate e non alterano la
  giacenza (le giacenze storiche sono già state caricate da inventory).
- Se un prodotto non ha l'aliquota IVA e la inserisci in un movimento,
  al salvataggio l'aliquota viene memorizzata nell'anagrafica prodotto.

## Dashboard <!-- route: admin/dashboard -->

La pagina iniziale riepiloga lo stato del parco e del magazzino.

- **Indicatori (KPI)**: automezzi attivi, scadenze aperte (con il conteggio
  delle scadute e delle imminenti), ricambi sottoscorta e litri erogati nel
  mese; sotto ogni valore un mini-grafico mostra l'andamento storico.
- **Grafico "Andamento"**: serie per anno di litri carburante, manutenzioni
  e documenti — utile per vedere la storia dell'attività.
- **Scadenze**: le più urgenti in cima (imminenti e scadute in rosso, con
  i km per quelle chilometriche).
- **Distribuzione scadenze**: ciambella per tipologia con totale al centro.
- **Ricambi sotto scorta**: i prodotti più critici da riordinare.

![Dashboard con KPI reali, grafico annuale e lista scadenze](img/dashboard.png)

## Anagrafiche

Clienti, fornitori e le tabelle territoriali (nazioni, province, città).

### Clienti <!-- route: admin/customers -->

Anagrafica dei clienti a cui si emettono documenti e fatture.

- **Ricerca e filtri**: campo ricerca in alto + filtri sulle colonne.
- **Nuovo cliente**: pulsante "+" → compila ragione sociale, contatti,
  indirizzo (nazione/provincia/città a tendina) e salva.
- **Modifica/Elimina**: icone sulla riga.
- **Stampa**: seleziona le righe con la spunta e usa Stampa per il PDF.

![Elenco clienti con ricerca e filtri](img/anagrafiche-clienti.png)

### Fornitori <!-- route: admin/suppliers -->

Anagrafica dei fornitori di ricambi e carburante.

- Stesse operazioni dei clienti: crea, modifica, elimina, stampa.
- Il fornitore è richiesto nei documenti di carico e nelle fatture.

![Elenco fornitori](img/anagrafiche-fornitori.png)

### Nazioni / Province / Città <!-- route: admin/locations -->

Tabelle territoriali usate dagli indirizzi di clienti e fornitori.

- Le tre liste sono collegate: una provincia appartiene a una nazione,
  una città a una provincia — le tendine si filtrano a cascata.
- Creazione e modifica dalle rispettive schede.

![Gestione nazioni, province e città](img/anagrafiche-locations.png)

## Officina

Parco automezzi, manutenzioni, scadenze e calendario.

### Veicoli <!-- route: admin/vehicles -->

Anagrafica del parco automezzi.

- **Ricerca**: per targa, marca e descrizione; filtri disponibili sulle
  colonne principali.
- **Nuovo veicolo**: pulsante "Nuovo veicolo" → targa, marca, descrizione,
  caratteristiche e chilometri attuali.
- **Km attuali**: sono il riferimento per le manutenzioni; vengono
  aggiornati automaticamente quando registri i km in una manutenzione.
- **Modifica/Elimina**: icone sulla riga; la scheda mostra anche i dati
  importati dal vecchio gestionale.

![Elenco veicoli](img/veicoli.png)

![Scheda di un nuovo veicolo: targa, marca, descrizione e caratteristiche](img/veicolo-nuovo.png)

### Marche <!-- route: admin/brands -->

Elenco delle marche dei veicoli (e dei prodotti).

- **Nuova marca**: pulsante "Nuova marca" → nome e salva.
- **Modifica/Elimina**: pulsanti sulla riga; l'eliminazione è bloccata se
  la marca è in uso.

![Elenco marche](img/marche.png)

### Caratteristiche <!-- route: admin/features -->

Attributi tecnici associabili ai veicoli (allestimenti, optional…).

- Gestione con creazione, modifica ed eliminazione dalle righe della tabella.
- Le caratteristiche assegnate compaiono nella scheda del veicolo.

![Elenco caratteristiche](img/caratteristiche.png)

### Manutenzione <!-- route: admin/maintenance -->

Registro degli interventi sui veicoli. La scheda ha tre tab:
Informazioni, Ricambi e Fatture.

![L'elenco delle manutenzioni registrate](img/manutenzioni.png)

**Tab Informazioni**

- **Veicolo**: campo ricercabile — cerca per targa, marca o descrizione.
- **Data / Ultimi km registrati / Km attuali / Differenza**: gli ultimi km
  sono in sola lettura e arrivano dal veicolo; inserendo i km attuali la
  differenza si calcola da sola — verde se positiva, rossa se negativa con
  avviso e richiesta di conferma al salvataggio. Al salvataggio i km attuali
  aggiornano il veicolo.
- **Lavoro eseguito**: descrizione libera dell'intervento.
- **Totale ore di lavorazione**: riquadro blu che riepiloga ore e costo
  manodopera di tutti i ricambi inseriti.
- **Salva / Annulla**: i pulsanti sono visibili solo in questo tab.
  Una manutenzione nuova va salvata una prima volta prima di poter
  inserire ricambi e fatture (gli altri tab mostrano l'avviso
  "Salva prima la manutenzione").

![Tab Informazioni: riga km con ultimi km, km attuali e differenza calcolata](img/manutenzione-informazioni.png)

![Su una manutenzione non salvata i tab Ricambi e Fatture mostrano l'avviso "Salva prima la manutenzione"](img/manutenzione-ricambi-bloccata.png)

**Tab Ricambi**

- Tabella con colonne Codice, Articolo, Q.tà, Prezzo, Sconto, Importo,
  Ore, €/h, Manodopera, I.v.a., Totale.
- **Aggiungere un ricambio**: scegli l'articolo con la ricerca (codice,
  alias o nome; la giacenza è colorata), quindi compila quantità, prezzo,
  sconto, ore e costo orario. Importo, Manodopera e Totale si calcolano da
  soli. Il pulsante **+ Aggiungi** scrive subito nel database e scarica la
  giacenza.
- **Eliminare un ricambio**: il pulsante ✕ sulla riga rimuove il movimento
  e ricarica in magazzino la quantità usata.
- **Costo orario**: è precompilato col valore globale
  (Impostazioni → Generali); se lo cambi, il nuovo valore diventa il
  default globale.
- **Prezzo/IVA**: alla selezione dell'articolo vengono proposti dal
  prodotto (altrimenti 0,00); un'IVA inserita su un articolo senza
  aliquota viene salvata nell'anagrafica.

![Il tab Ricambi: la riga di inserimento segue le colonne, importo/manodopera/totale si calcolano da soli](img/manutenzione-ricambi.png)

**Tab Fatture**

- Elenco delle fatture fornitore collegate all'intervento;
  "Collega fattura" aggiunge il riferimento scelto dalla tendina,
  "Scollega" lo rimuove.

**Stampa**: dalla lista o dalla scheda di visualizzazione puoi stampare la
scheda manutenzione in PDF (dati veicolo, ricambi e manodopera).

### Voci di scadenza <!-- route: admin/expirations -->

Elenco delle scadenze del parco (assicurazioni, revisioni, estintori,
chilometriche) con i relativi stati.

- La tabella mostra veicolo, etichetta della scadenza, data o km di
  scadenza e stato; le scadute sono evidenziate.
- I filtri di colonna permettono di isolare rapidamente scadute e
  imminenti.
- **Nuova voce di scadenza**: il pulsante "+" crea un'etichetta (tipo di
  scadenza) riusabile sui veicoli.
- Le occorrenze (storico rinnovi/scadenze gestite) restano consultabili
  dal dettaglio.

![Le scadenze del parco con stato e urgenza](img/scadenze.png)

![Creazione di una nuova voce di scadenza](img/scadenza-nuova.png)

### Calendario <!-- route: admin/calendar -->

Vista mensile/settimanale degli impegni: scadenze, manutenzioni
pianificate e parcheggi.

- Naviga con le frecce tra i periodi; clic su un giorno per i dettagli.
- I colori distinguono i tipi di evento.

![Il calendario mensile con scadenze e impegni](img/calendario.png)

## Documenti

Movimenti di magazzino (carichi, scarichi) e fatture.

### Carichi <!-- route: admin/documents -->

Documenti di carico (DDT fornitori e carichi manuali): aumentano le
giacenze.

![L'elenco dei documenti di carico](img/documenti-carichi.png)

1. Clicca l'icona **+** in alto e compila la scheda Informazioni
   (numero, data, fornitore), poi **salva**: finché il documento non è
   salvato il tab Movimenti è coperto da un avviso.
2. Nel tab Movimenti scegli l'articolo con la ricerca (codice, alias o
   nome — la giacenza è colorata); prezzo e IVA si propongono dal
   prodotto.
3. Imposta quantità, sconto e premi **Aggiungi**: il movimento viene
   scritto subito e la giacenza aumenta.
4. Una riga eliminata con ✕ storna la quantità dal magazzino.
5. **Associa a fattura**: i carichi non ancora fatturati hanno l'icona di
   collegamento sulla riga.
6. Chiudi con **Chiudi** o il ✕ in alto a destra.

![La scheda di un carico: il tab Movimenti resta bloccato finché il documento non è salvato](img/carico-movimenti-bloccati.png)

![Ricerca articolo: digitando "cinghia" compaiono codici, alias (in blu col codice radice) e giacenze colorate](img/carico-ricerca-articolo.png)

### Scarichi <!-- route: admin/unloads -->

Documenti di scarico (uscite da magazzino): diminuiscono le giacenze.

- Stessa scheda dei carichi: intestazione, poi tab Movimenti con ricerca
  articolo e riga di inserimento.
- Il prezzo proposto è il prezzo di vendita del prodotto (nei carichi è
  il prezzo d'acquisto/ingrosso).
- Eliminando uno scarico la quantità torna in giacenza.

![L'elenco dei documenti di scarico](img/documenti-scarichi.png)

### Fatture <!-- route: admin/invoices -->

Registro delle fatture fornitore (e clienti).

- **Nuova fattura**: numero, data, partner, importi; i documenti collegati
  si vedono nel dettaglio.
- Dalla riga di un carico/scarico puoi associarlo a una fattura con
  l'icona di collegamento.
- La stampa PDF produce il riepilogo della fattura con i movimenti.

![L'elenco delle fatture](img/documenti-fatture.png)

![Scheda di una nuova fattura](img/fattura-nuova.png)

## Rifornimenti

Punti di rifornimento, carichi carburante, erogazioni ai veicoli e
statistiche.

### Punti di rifornimento <!-- route: admin/refuelling-stations -->

Le stazioni/pompe da cui si eroga il carburante.

- **Nuovo punto**: pulsante "+" → nome, indirizzo, tipo e salva.
- Ogni erogazione in "Gestione carburante" fa riferimento a un punto.

![I punti di rifornimento](img/rifornimenti-stazioni.png)

### Carico <!-- route: admin/refuelling-load -->

Registro dei carichi di carburante acquistati (ingressi in cisterna).

- Ogni riga registra data, prodotto carburante, quantità e fornitore.
- I carichi alimentano la disponibilità usata dalle erogazioni.

![I carichi di carburante](img/rifornimenti-carico.png)

### Gestione carburante <!-- route: admin/refuelling -->

Le erogazioni di carburante ai veicoli.

- **Nuovo rifornimento**: "+" → veicolo (ricerca per targa/nome), punto
  di rifornimento, data/ora, litri, km del veicolo al momento del
  rifornimento, eventuale fornitore.
- **Km dalla precedente**: il sistema calcola la differenza con
  l'ultimo rifornimento dello stesso veicolo e avvisa se i valori non
  tornano.
- La direzione "ingresso" (carico in cisterna) e "uscita" (erogazione al
  veicolo) sono distinte nella tabella.

![Le erogazioni di carburante ai veicoli](img/rifornimenti-gestione.png)

![Scheda di un nuovo rifornimento: veicolo, stazione, litri e km](img/rifornimento-nuovo.png)

### Statistiche <!-- route: admin/refuelling-stats -->

KPI e grafici sui rifornimenti.

- Totali per periodo, consumi medi e andamenti per veicolo/carburante.
- Filtri sul periodo per analisi puntuali.

![Le statistiche dei rifornimenti](img/rifornimenti-statistiche.png)

## Magazzino

Gestione di categorie merceologiche, articoli e giacenze.

### Categorie <!-- route: admin/categories -->

Elenco delle categorie merceologiche dei prodotti.

- **Nuova categoria**: pulsante "Nuova categoria" → inserisci il nome e
  salva.
- **Modifica**: pulsante matita sulla riga → correggi e salva.
- **Elimina**: pulsante ✕ sulla riga; è consentito solo se la categoria
  non è usata da nessun prodotto.
- La ricerca filtra per nome; l'importazione dal vecchio gestionale
  conserva i collegamenti originali.

![Elenco categorie merceologiche](img/categorie.png)

### Prodotti <!-- route: admin/products -->

Anagrafica degli articoli di magazzino.

- **Ricerca**: filtra per codice, nome e alias; i filtri di colonna
  restringono per categoria e altri attributi.
- **Nuovo prodotto**: pulsante "+". Campi principali: codice (SKU), nome,
  categoria, prezzo vendita, prezzo ingrosso, aliquota IVA, prodotto di
  cui è alias (opzionale).
- **Alias**: un articolo può essere alias di un altro (codice
  equivalente). Nelle ricerche dei movimenti un alias mostra la famiglia
  completa e viene scaricata la giacenza del codice selezionato.
- **Aliquota IVA**: se assente viene proposta 0% nei movimenti; la prima
  aliquota inserita a mano viene salvata sull'anagrafica.
- **Modifica/Elimina**: pulsanti sulla riga. Il codice resta il
  riferimento per giacenze e movimenti.

![L'elenco prodotti con ricerca e filtri per colonna](img/prodotti.png)

### Giacenze <!-- route: admin/stocks -->

Consultazione e rettifica delle quantità in magazzino.

- La tabella mostra per ogni articolo la giacenza, i totali carico/scarico
  e la soglia minima; gli articoli sotto soglia sono evidenziati.
- **Modifica giacenza**: apri la riga e imposta la quantità (inventario):
  il valore viene fissato a quello indicato.
- **Soglia minima**: impostala per attivare gli avvisi di sottoscorta
  (visibili anche in Dashboard).
- I movimenti di carico/scarico e i ricambi di manutenzione aggiornano la
  giacenza automaticamente.

![Le giacenze: articoli sotto soglia evidenziati](img/giacenze.png)

## Impostazioni <!-- route: admin/settings -->

Configurazione dell'applicazione, organizzata in tab.

![La pagina Impostazioni](img/impostazioni.png)

- **Utenti**: elenco degli utenti con ruolo e stato; creazione, modifica
  (ruolo, attivo) ed eliminazione.
- **Permessi**: matrice dei permessi per ruolo e permessi diretti per
  utente. Il ruolo Amministratore non è modificabile.
- **Password**: cambio della propria password (inserisci l'attuale e due
  volte la nuova).
- **Importazioni**: reimporta i dati dalle tabelle del vecchio gestionale.
  Ogni tile svuota la propria area dati e la reimporta (configurazione,
  utenti e permessi non vengono toccati). La tile "Importa tutto" esegue
  l'intera sequenza nell'ordine corretto di dipendenza mostrando
  l'avanzamento.
- **Generali**: valori globali — costo orario della manodopera (€/h),
  usato come default nei ricambi di manutenzione.
- **Interfaccia**: posizione, durata e stile dei messaggi di notifica.
- **Tipi documento**: tipologie dei documenti di magazzino (DDT,
  fatture…) con gestione completa.
- **Menu**: editor della struttura del menu laterale (attivazione menu
  personalizzato, riordino, voci e sottomenu).

![Impostazioni → Generali: il costo orario globale usato dalle manutenzioni](img/impostazioni-generali.png)

![Impostazioni → Importazioni: la tile "Importa tutto" reimporta tutti i dati in ordine](img/impostazioni-importazioni.png)
