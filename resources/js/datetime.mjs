/*
 * Datums- und Zeitauswahl für Formulare der Website (Datentabellen, Core\Data\DataForms) – dataform.js lädt das Modul erst,
 * wenn ein Formular ein Datums- oder Zeitfeld hat. Farben aus den Token des Kits (css/datetime.css), Sprache der Website.
 *   import(url).then(m => m.enhance(form, { lang, mode, css }))
 */
import { initDatetime } from './_datetime.js';

export function enhance(scope, cfg = {}) {
  initDatetime(scope, { base: import.meta.url, lang: cfg.lang, mode: cfg.mode, theme: 'dt-site', css: cfg.css ? [cfg.css] : [] });
}
