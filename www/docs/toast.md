# Toast.js — Notifiche toast

Componente riutilizzabile per mostrare messaggi di notifica all'utente.
Vive in `public/assets/fairy-bus/js/core/Toast.js` e usa gli stili di
`public/assets/fairy-bus/css/components/toast.css` (già incluso in `layout.twig`).

## Caratteristiche

- **Contenuto HTML**: il contenuto del toast è sempre interpretato come HTML,
  quindi si possono inserire `<strong>`, liste, link, ecc.
- **Icona per tipo**: ogni tipo mostra automaticamente un'icona (info, check,
  triangolo di avviso, X-circle) colorata in base al tipo.
- **Posizionamento**: 7 posizioni supportate, default `center` (centro pagina).
- **Preferenze globali**: in Impostazioni → Interfaccia si configurano
  posizione, durata e stile di default per tutte le pagine
  (`fb_configuration`: `toast_position`, `toast_duration`, `toast_style`).
  Il layout le espone in `window.FB.toast` e il costruttore le usa come
  default quando `position`/`duration`/`banner` non sono passati.
- **Shadow-box**: bordo accentuato a sinistra + ombra marcata per renderlo
  ben visibile sopra il contenuto.
- **Chiusura manuale**: icona × in alto a destra.
- **Pausa in hover**: il timer di scomparsa si sospende quando il mouse è
  sopra il toast e riprende dal tempo residuo all'uscita.
- **Timer**: default 5 secondi; `0` = persistente (chiusura solo manuale).

## Struttura

```text
.fb-toast-container.fb-toast-pos-{position}   ← un contenitore per posizione
└── .fb-toast.fb-toast-{type}
    ├── .fb-toast-icon     (SVG automatica per tipo)
    ├── .fb-toast-content  (HTML libero)
    └── .fb-toast-close    (bottone ×)
```

I contenitori sono creati on-demand in `document.body`, uno per posizione,
con `pointer-events: none` sul contenitore e `auto` sui toast.

## API

### `new Toast()`

Il costruttore non richiede parametri; i contenitori sono creati lazy.

### `showToast(options)`

| Opzione    | Tipo      | Default    | Descrizione                                    |
|------------|-----------|------------|------------------------------------------------|
| `content`  | `string`  | —          | Contenuto HTML (obbligatorio)                  |
| `type`     | `string`  | `"notice"` | `notice` \| `success` \| `warning` \| `error`  |
| `position` | `string`  | `"center"` | vedi posizioni sotto                           |
| `duration` | `number`  | `5000`     | ms di permanenza; `0` = persistente            |
| `banner`   | `boolean` | `false`    | `true` = stile banner a tutta larghezza        |

Restituisce l'`HTMLElement` del toast.

### Scorciatoie

```js
toast.showToastNotice(content, position?, duration?, banner?)
toast.showToastSuccess(content, position?, duration?, banner?)
toast.showToastWarning(content, position?, duration?, banner?)
toast.showToastError(content, position?, duration?, banner?)
```

### Posizioni

`top-left`, `top-center`, `top-right`, `center` (default),
`bottom-left`, `bottom-center`, `bottom-right`

Valori non validi ricadono sui default (`notice` / `center`).

### Tema `banner`

Con `banner: true` il toast diventa un **banner a tutta larghezza**:

- larghezza 100% della pagina, bordi destro/sinistro assenti
- bordi superiore e inferiore (3px) in tonalità più scura del colore del tipo
- sfondo pieno del colore del tipo, testo bianco ad alto contrasto
- padding verticale 16px, testo al centro e limitato al **40%** della
  larghezza (va a capo su più righe se lungo)
- **nessuna icona**: né quella del tipo né la × — il contenuto è solo il
  messaggio HTML; si chiude con un click ovunque sul banner
- `position` determina solo l'ancora verticale: `top-*` → alto,
  `bottom-*` → basso, qualsiasi altra → centro

## Uso

```js
import Toast from "../core/Toast.js";

const toast = new Toast();

// salvataggio riuscito — centro pagina, 5s
toast.showToastSuccess("Rifornimento registrato.");

// errore con HTML, in alto a destra per 8 secondi
toast.showToastError(
    "Impossibile salvare: <strong>campo obbligatorio mancante</strong>.",
    "top-right",
    8000
);

// avviso persistente, in basso al centro
toast.showToastWarning("Sessione in scadenza", "bottom-center", 0);

// uso generico
toast.showToast({
    content: "<ul><li>Voce 1</li><li>Voce 2</li></ul>",
    type: "notice",
    position: "center",
    duration: 5000,
});

// banner a tutta larghezza in cima alla pagina
toast.showToastSuccess("Importazione completata: 20.063 record.", "top-center", 6000, true);

// banner di errore persistente al centro
toast.showToastError("Connessione al database fallita.", "center", 0, true);
```

## Note

- I toast sono elementi `fixed` normali: un `<dialog>` aperto con
  `showModal()` sta nel top-layer del browser e copre qualsiasi z-index.
  Mostra il toast dopo aver chiuso il modale (come fatto nei submit dei form).
- L'HTML del `content` non viene escapato: sanitizza input esterni prima di
  passarlo a `showToast`.
- La classe precedente `ToastHelper` resta disponibile per compatibilità,
  ma le nuove pagine dovrebbero usare `Toast`.
