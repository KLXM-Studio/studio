/*
 * Video-Adresse wie Core\VideoLinks::parse: youtube.com/watch?v=ID, youtube.com/shorts/ID, youtu.be/ID, vimeo.com/ID.
 * Verwaltung: Bedingung show_if '~video' (Zusatzfeld „Video ohne Lightbox öffnen“) und Option „Ohne Lightbox öffnen“ der Linkauswahl.
 */
const RE = /^https?:\/\/(?:(?:www\.|m\.)?youtube\.com\/(?:watch\?(?:[^#]*&)?v=[\w-]{11}(?:[&#]|$)|shorts\/[\w-]{11}\/?(?:[?#]|$))|youtu\.be\/[\w-]{11}\/?(?:[?#]|$)|(?:www\.)?vimeo\.com\/\d{6,12}\/?(?:[?#]|$))/i;

export const isVideoUrl = v => RE.test(String(v || '').trim());
