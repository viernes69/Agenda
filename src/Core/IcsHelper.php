<?php
/**
 * Agenduy - ICS / Google Calendar helper
 *
 * Genera links y archivos .ics para que un cliente pueda agregar
 * su reserva a Google Calendar, Apple Calendar, Outlook, etc.
 *
 * NOTA: Si usás Service Account para crear el evento, Google no
 * envía invitaciones a los attendees (requiere domain-wide delegation
 * en Google Workspace). Por eso generamos un .ics/link que el cliente
 * abre y se autoagrega a su propio calendar.
 */

declare(strict_types=1);

namespace Agenduy\Core;

final class IcsHelper
{
    /**
     * Genera un link "Add to Google Calendar" prellenado.
     */
    public static function googleLink(array $event): string
    {
        $start = self::formatDateTime($event['start'] ?? null);
        $end   = self::formatDateTime($event['end'] ?? null);

        $params = [
            'action'   => 'TEMPLATE',
            'text'     => (string)($event['title'] ?? 'Reserva'),
            'dates'    => $start . '/' . $end,
            'details'  => (string)($event['description'] ?? ''),
            'location' => (string)($event['location'] ?? ''),
        ];
        return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
    }

    /**
     * Genera el contenido de un archivo .ics (RFC 5545).
     */
    public static function buildIcs(array $event, string $uid): string
    {
        $dtstamp = gmdate('Ymd\THis\Z');
        $dtstart = self::formatDateTime($event['start'] ?? null, true);
        $dtend   = self::formatDateTime($event['end'] ?? null, true);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Agenduy//Reservas//ES',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $dtstamp,
            'DTSTART:' . $dtstart,
            'DTEND:' . $dtend,
            'SUMMARY:' . self::escape((string)($event['title'] ?? 'Reserva')),
            'DESCRIPTION:' . self::escape((string)($event['description'] ?? '')),
        ];
        if (!empty($event['location'])) {
            $lines[] = 'LOCATION:' . self::escape((string)$event['location']);
        }
        if (!empty($event['organizer_email'])) {
            $lines[] = 'ORGANIZER;CN=' . self::escape((string)($event['organizer_name'] ?? 'Agenduy'))
                . ':mailto:' . $event['organizer_email'];
        }
        // Alarma 1 día antes por email
        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'ACTION:EMAIL';
        $lines[] = 'DESCRIPTION:Recordatorio de reserva';
        $lines[] = 'TRIGGER:-P1D';
        $lines[] = 'END:VALARM';
        // Alarma 30 min antes popup
        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'ACTION:DISPLAY';
        $lines[] = 'DESCRIPTION:Tu reserva es pronto';
        $lines[] = 'TRIGGER:-PT30M';
        $lines[] = 'END:VALARM';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Formatea una fecha/hora al formato ICS (UTC) o Google link (local naive).
     *
     * @param string|\DateTimeInterface|null $when
     * @param bool $utc true para .ics (YYYYMMDDTHHMMSSZ), false para Google link (YYYYMMDDTHHMMSS)
     */
    private static function formatDateTime($when, bool $utc = false): string
    {
        if ($when === null) {
            $when = new \DateTimeImmutable();
        }
        if (is_string($when)) {
            try {
                $when = new \DateTimeImmutable($when);
            } catch (\Exception) {
                $when = new \DateTimeImmutable();
            }
        }
        if ($utc) {
            $when = $when->setTimezone(new \DateTimeZone('UTC'));
            return $when->format('Ymd\THis\Z');
        }
        // Google link expects "floating" local time in basic format
        return $when->format('Ymd\THis');
    }

    private static function escape(string $text): string
    {
        // RFC 5545: escapar \, ; , , y saltos de línea
        $text = str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', ''], $text);
        return $text;
    }
}
