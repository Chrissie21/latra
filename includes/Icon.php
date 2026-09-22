<?php
/**
 * Icon helper: maps legacy FontAwesome class names to Lucide icon names.
 *
 * The UI renders icons as <i data-lucide="name"> which the Lucide
 * runtime replaces with inline SVGs at run time.
 */
class Icon {

    /** @var array<string,string> FA class -> Lucide name */
    private const MAP = [
        'fa-table-cells-large'     => 'layout-grid',
        'fa-truck'                 => 'truck',
        'fa-truck-fast'            => 'truck',
        'fa-truck-arrow-right'     => 'truck',
        'fa-truck-ramp-box'        => 'package',
        'fa-list-check'            => 'list-checks',
        'fa-bolt'                  => 'zap',
        'fa-paper-plane'           => 'send',
        'fa-clock-rotate-left'     => 'history',
        'fa-clipboard-check'       => 'clipboard-check',
        'fa-clipboard-list'        => 'clipboard-list',
        'fa-heart-pulse'           => 'heart-pulse',
        'fa-sliders'               => 'sliders-horizontal',
        'fa-bars-staggered'        => 'align-justify',
        'fa-right-from-bracket'    => 'log-out',
        'fa-right-to-bracket'      => 'log-in',
        'fa-rotate'                => 'rotate-cw',
        'fa-rotate-right'          => 'rotate-cw',
        'fa-arrows-rotate'         => 'rotate-cw',
        'fa-arrow-rotate-right'    => 'rotate-cw',
        'fa-gear'                  => 'settings',
        'fa-gears'                 => 'settings',
        'fa-user-gear'             => 'user-cog',
        'fa-user-shield'           => 'shield-check',
        'fa-shield-heart'          => 'shield',
        'fa-location-dot'          => 'map-pin',
        'fa-map-pin'               => 'map-pin',
        'fa-map-location-dot'      => 'map-pinned',
        'fa-chevron-down'          => 'chevron-down',
        'fa-chevron-right'         => 'chevron-right',
        'fa-chevron-left'          => 'chevron-left',
        'fa-clock'                 => 'clock',
        'fa-circle-check'          => 'circle-check',
        'fa-triangle-exclamation'  => 'triangle-alert',
        'fa-circle-xmark'          => 'circle-x',
        'fa-xmark'                 => 'x',
        'fa-times'                 => 'x',
        'fa-spinner'               => 'loader-circle',
        'fa-layer-group'           => 'layers',
        'fa-filter'                => 'filter',
        'fa-circle-info'           => 'info',
        'fa-circle'                => 'circle',
        'fa-circle-dot'            => 'circle-dot',
        'fa-location-crosshairs'   => 'crosshair',
        'fa-tower-cell'            => 'radio-tower',
        'fa-tower-broadcast'       => 'radio-tower',
        'fa-satellite-dish'        => 'satellite-dish',
        'fa-satellite'             => 'satellite',
        'fa-route'                 => 'route',
        'fa-map'                   => 'map',
        'fa-message'               => 'message-square',
        'fa-paperclip'             => 'paperclip',
        'fa-inbox'                 => 'inbox',
        'fa-check-double'          => 'check-check',
        'fa-wifi'                  => 'wifi',
        'fa-plug'                  => 'plug',
        'fa-plug-circle-xmark'     => 'plug-zap',
        'fa-play'                  => 'play',
        'fa-pause'                 => 'pause',
        'fa-power-off'             => 'power',
        'fa-download'              => 'download',
        'fa-upload'                => 'upload',
        'fa-save'                  => 'save',
        'fa-copy'                  => 'copy',
        'fa-pen'                   => 'square-pen',
        'fa-pen-to-square'         => 'square-pen',
        'fa-trash'                 => 'trash-2',
        'fa-eye'                   => 'eye',
        'fa-reply'                 => 'reply',
        'fa-code'                  => 'code',
        'fa-arrow-left'            => 'arrow-left',
        'fa-arrow-right'           => 'arrow-right',
        'fa-arrow-up-right-from-square' => 'arrow-up-right-from-square',
        'fa-flag'                  => 'flag',
        'fa-flag-checkered'        => 'flag',
        'fa-check'                 => 'check',
        'fa-ban'                   => 'ban',
        'fa-hourglass-half'        => 'hourglass',
        'fa-question'              => 'circle-help',
        'fa-magnifying-glass'      => 'search',
        'fa-gauge-high'            => 'gauge',
        'fa-stopwatch'             => 'timer',
        'fa-envelope-open-text'    => 'mail-open',
        'fa-note-sticky'           => 'sticky-note',
        'fa-bell'                  => 'bell',
        'fa-plus'                  => 'plus',
        'fa-minus'                 => 'minus',
        'fa-lock'                  => 'lock',
        'fa-book'                  => 'book',
        'fa-list'                  => 'list',
        'fa-ellipsis'              => 'ellipsis',
        'fa-database'              => 'database',
        'fa-server'                => 'server',
        'fa-hard-drive'            => 'hard-drive',
        'fa-wrench'                => 'wrench',
        'fa-wave-square'           => 'activity',
        'fa-signal'                => 'signal',
        'fa-phone-sos'             => 'phone',
        'fa-diagram-project'       => 'workflow',
        'fa-file-csv'              => 'file-spreadsheet',
        'fa-table'                 => 'table',
        'fa-battery-half'          => 'battery-medium',
    ];

    /**
     * Correct a pre-stripped name (the old converter dropped the "fa-" prefix
     * before mapping). Looks up "fa-" + token, otherwise returns token as-is.
     */
    public static function fixStripped(string $token): string {
        return self::MAP['fa-' . $token] ?? $token;
    }

    /**
     * Translate a FontAwesome icon token (or any class string) to a Lucide name.
     * Already-Lucide names pass through untouched.
     */
    public static function lucide(string $classes = ''): string {
        $classes = trim($classes);
        if ($classes === '') {
            return 'circle-help';
        }
        foreach (preg_split('/\s+/', $classes) as $token) {
            if ($token === '') {
                continue;
            }
            if (isset(self::MAP[$token])) {
                return self::MAP[$token];
            }
            if (!str_starts_with($token, 'fa-')) {
                return $token; // already a Lucide name
            }
        }
        return 'circle-help';
    }
}