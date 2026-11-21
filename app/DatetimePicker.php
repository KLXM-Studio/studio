<?php
declare(strict_types=1);

namespace Core;

/**
 * Datums- und Zeitauswahl: barrierefreier Picker a11y_datetime (public/assets/vendor/a11y_datetime/{version}/,
 * resources/js/_datetime.js) statt der Auswahl des Browsers – Progressive Enhancement, die Felder bleiben
 * <input type="date|time|datetime-local"> mit dem Format des Servers.
 *
 * Grundeinstellung sys.datetime_picker (Verwaltung und Website):
 *   picker   Kalender überall, auch auf Telefonen (Standard)
 *   desktop  Kalender nur mit Maus/Touchpad; Telefone und Tablets behalten die Auswahl des Systems
 *   native   immer die Auswahl des Browsers
 * Ein Kit schaltet den Kalender für die Website ab: theme.php 'datetime_picker' => false (die Verwaltung bleibt).
 */
final class DatetimePicker
{
    public const MODES = ['picker', 'desktop', 'native'];

    /** Grundeinstellung (ungültig/leer = picker) */
    public static function mode(): string
    {
        $m = (string) (app()->settings->get('sys.datetime_picker') ?: 'picker');
        return in_array($m, self::MODES, true) ? $m : 'picker';
    }

    /** Modus auf der Website: Kit-Schalter 'datetime_picker' => false → native */
    public static function siteMode(): string
    {
        return (app()->theme->def['datetime_picker'] ?? true) === false ? 'native' : self::mode();
    }

    /** Auswahl für die Grundeinstellungen */
    public static function options(): array
    {
        return [
            'picker' => __('Barrierefreier Kalender – überall, auch auf Telefonen'),
            'desktop' => __('Kalender am Computer – Telefone und Tablets nutzen die Auswahl des Systems'),
            'native' => __('Immer die Auswahl des Browsers'),
        ];
    }

    /** Attribut am <html> der Verwaltung bzw. an der Werkzeugleiste (resources/js/admin.js liest data-dt) */
    public static function adminAttr(): string
    {
        $m = self::mode();
        return $m === 'picker' ? '' : ' data-dt="' . e($m) . '"';
    }

    /**
     * Attribut am Formular der Website (dataform.js lädt js/datetime.mjs erst, wenn das Formular ein Datums- oder
     * Zeitfeld hat); leer, wenn die Website die Auswahl des Browsers nutzt.
     */
    public static function formAttr(): string
    {
        $m = self::siteMode();
        if ($m === 'native') return '';
        return ' data-dt="' . json_attr([
            'js' => asset('js/datetime.mjs'), 'css' => asset('css/datetime.css'),
            'lang' => Lang::current() === 'de' ? 'de' : 'en', 'mode' => $m,
        ]) . '"';
    }
}
