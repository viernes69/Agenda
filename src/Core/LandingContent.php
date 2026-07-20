<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Contenido SEO y de marca para la landing pública de Agendarte UY.
 */
final class LandingContent
{
    public const BRAND = 'Agendarte UY';
    public const TAGLINE = 'Tu tiempo, tus servicios, tu agenda.';
    public const SITE_DESCRIPTION = 'Plataforma de reservas online en Uruguay. Agenda digital, turnos 24/7 y gestión de servicios para profesionales, salones, clínicas y negocios.';

    /** @return list<string> */
    public static function metaKeywords(): array
    {
        return [
            'reservas online Uruguay',
            'turnos online Uruguay',
            'agenda online Uruguay',
            'agendar turno online',
            'plataforma de reservas',
            'sistema de reservas online',
            'gestión de turnos',
            'agenda para profesionales',
            'software de reservas Uruguay',
            'Agendarte UY',
        ];
    }

    /** @return list<string> */
    public static function benefits(): array
    {
        return [
            'Agenda online disponible las 24 horas',
            'Gestión de servicios, horarios y disponibilidad',
            'Reservas desde celulares y computadoras',
            'Menos llamadas y mensajes para coordinar',
            'Mayor visibilidad para tu negocio',
            'Organización centralizada de turnos',
            'Experiencia sencilla para tus clientes',
        ];
    }

    /** @return array<string,array{title:string,description:string,keywords:list<string>}> */
    public static function categories(): array
    {
        return [
            'peluquerias' => [
                'title' => 'Peluquerías y salones de belleza',
                'description' => 'Reservá turnos en peluquerías y salones con agenda online en Uruguay.',
                'keywords' => ['reservar peluquería online', 'salones de belleza Uruguay'],
            ],
            'barberias' => [
                'title' => 'Barberías',
                'description' => 'Agendá tu corte o barba en barberías con reservas online.',
                'keywords' => ['agendar barbería', 'turnos barbería Uruguay'],
            ],
            'estetica' => [
                'title' => 'Manicura, pedicura y estética',
                'description' => 'Encontrá turnos para tratamientos de estética y cuidado personal.',
                'keywords' => ['turnos para estética', 'reservar manicura'],
            ],
            'bienestar' => [
                'title' => 'Masajes y bienestar',
                'description' => 'Reservá sesiones de masajes, spa y servicios de bienestar.',
                'keywords' => ['masajes online', 'bienestar Uruguay'],
            ],
            'psicologia' => [
                'title' => 'Psicólogos y terapeutas',
                'description' => 'Agenda online para consultorios de psicología y terapia.',
                'keywords' => ['agenda para psicólogos', 'turnos terapia Uruguay'],
            ],
            'odontologia' => [
                'title' => 'Odontólogos y clínicas',
                'description' => 'Solicitá turnos odontológicos con disponibilidad en tiempo real.',
                'keywords' => ['reservar odontólogo', 'turnos dentales online'],
            ],
            'salud' => [
                'title' => 'Médicos y profesionales de la salud',
                'description' => 'Turnos médicos online para consultorios y centros de salud.',
                'keywords' => ['turnos médicos online', 'agenda para consultorios'],
            ],
            'nutricion' => [
                'title' => 'Nutricionistas',
                'description' => 'Reservá consultas con nutricionistas de forma simple.',
                'keywords' => ['nutricionista turnos online'],
            ],
            'veterinarias' => [
                'title' => 'Veterinarias',
                'description' => 'Agendá visitas veterinarias para tus mascotas.',
                'keywords' => ['turnos para veterinaria', 'veterinaria online Uruguay'],
            ],
            'gimnasios' => [
                'title' => 'Gimnasios y entrenadores personales',
                'description' => 'Reservá clases, entrenamientos y servicios fitness.',
                'keywords' => ['reservar gimnasio', 'entrenador personal agenda'],
            ],
            'deporte' => [
                'title' => 'Canchas deportivas',
                'description' => 'Reservá canchas y espacios deportivos online.',
                'keywords' => ['reservar cancha', 'turnos deportivos Uruguay'],
            ],
            'talleres-mecanicos' => [
                'title' => 'Talleres mecánicos',
                'description' => 'Coordiná turnos para service y reparaciones vehiculares.',
                'keywords' => ['agenda para talleres mecánicos', 'turnos mecánico'],
            ],
            'tecnicos' => [
                'title' => 'Servicios técnicos',
                'description' => 'Profesionales técnicos con agenda online para visitas y reparaciones.',
                'keywords' => ['servicios técnicos turnos'],
            ],
            'fotografia' => [
                'title' => 'Fotógrafos',
                'description' => 'Reservá sesiones fotográficas y producciones.',
                'keywords' => ['fotógrafo agenda online'],
            ],
            'academias' => [
                'title' => 'Academias y clases particulares',
                'description' => 'Gestioná clases, talleres y capacitaciones con reservas online.',
                'keywords' => ['academias turnos online', 'clases particulares agenda'],
            ],
            'consultorias' => [
                'title' => 'Consultorías y servicios profesionales',
                'description' => 'Agenda para consultores, asesores y servicios profesionales.',
                'keywords' => ['consultoría turnos online', 'servicios profesionales Uruguay'],
            ],
        ];
    }

    /** @return list<array{q:string,a:string}> */
    public static function faq(): array
    {
        return [
            [
                'q' => '¿Qué es Agendarte UY?',
                'a' => 'Agendarte UY es una plataforma de reservas online que permite encontrar profesionales y comercios, consultar sus servicios y solicitar turnos desde cualquier dispositivo.',
            ],
            [
                'q' => '¿Cómo puedo reservar un turno?',
                'a' => 'Buscá el servicio o profesional que necesitás, seleccioná una fecha y un horario disponible, completá tus datos y confirmá la reserva.',
            ],
            [
                'q' => '¿Puedo reservar desde el celular?',
                'a' => 'Sí. Agendarte UY está diseñada para funcionar desde celulares, tablets y computadoras.',
            ],
            [
                'q' => '¿Qué tipos de servicios puedo encontrar?',
                'a' => 'La plataforma puede incluir servicios de belleza, salud, bienestar, deporte, educación, reparaciones, asesoramiento profesional y muchas otras categorías.',
            ],
            [
                'q' => '¿Cómo registro mi negocio?',
                'a' => 'Seleccioná “Registrar mi negocio”, completá la información solicitada y configurá tus servicios, horarios y disponibilidad. También podés registrarte con Google.',
            ],
            [
                'q' => '¿Puedo utilizar Agendarte UY si trabajo de manera independiente?',
                'a' => 'Sí. La plataforma está pensada tanto para empresas como para profesionales independientes que trabajan con agenda previa.',
            ],
        ];
    }

    /** @return array<string,array{title:string,description:string,keywords:list<string>}> */
    public static function locations(): array
    {
        return [
            'durazno' => [
                'title' => 'Durazno',
                'description' => 'Reservas online y turnos para servicios en Durazno, Uruguay.',
                'keywords' => ['reservas online en Durazno', 'turnos online en Durazno', 'servicios con agenda en Uruguay'],
            ],
            'montevideo' => [
                'title' => 'Montevideo',
                'description' => 'Encontrá profesionales y negocios con agenda online en Montevideo.',
                'keywords' => ['reservas online Montevideo', 'turnos Montevideo'],
            ],
            'canelones' => [
                'title' => 'Canelones',
                'description' => 'Agendá servicios en Canelones con disponibilidad online.',
                'keywords' => ['turnos online Canelones', 'agenda Canelones'],
            ],
        ];
    }

    public static function jsonLdOrganization(string $siteUrl): string
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => self::BRAND,
            'url' => $siteUrl,
            'logo' => rtrim($siteUrl, '/') . '/src/media/logo/logo-horizontal.png',
            'description' => self::SITE_DESCRIPTION,
            'areaServed' => [
                '@type' => 'Country',
                'name' => 'Uruguay',
            ],
            'slogan' => self::TAGLINE,
        ];
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /** @return list<string> */
    public static function categorySearchTerms(string $slug): array
    {
        $categories = self::categories();
        if (!isset($categories[$slug])) {
            return [$slug];
        }

        $cat = $categories[$slug];
        $terms = [$slug];
        foreach ($cat['keywords'] ?? [] as $keyword) {
            $terms[] = (string)$keyword;
        }

        $titleWords = preg_split('/\s+/u', strtolower((string)($cat['title'] ?? ''))) ?: [];
        foreach ($titleWords as $word) {
            $word = trim($word, '.,;:!?');
            if (mb_strlen($word) >= 4) {
                $terms[] = $word;
            }
        }

        $unique = [];
        foreach ($terms as $term) {
            $term = strtolower(trim($term));
            if ($term === '' || mb_strlen($term) < 3) {
                continue;
            }
            $unique[$term] = true;
        }

        return array_keys($unique);
    }

    /** @return list<array<string,mixed>> */
    public static function commercesForCategory(string $slug, int $limit = 24): array
    {
        $terms = self::categorySearchTerms($slug);
        if ($terms === []) {
            return [];
        }

        $conditions = [];
        $params = [];
        foreach ($terms as $i => $term) {
            $key = ':t' . $i;
            $params[$key] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
            $conditions[] = "(lower(r.nombre) LIKE lower({$key})
                OR lower(r.tipo) LIKE lower({$key})
                OR lower(r.descripcion) LIKE lower({$key})
                OR lower(c.nombre) LIKE lower({$key})
                OR lower(c.ciudad) LIKE lower({$key}))";
        }

        $sql = 'SELECT c.slug, c.nombre, c.ciudad, r.nombre AS rubro_nombre
                FROM commerces c
                LEFT JOIN rubros r ON r.id_rubro = c.id_rubro
                WHERE c.status IN (\'trial\',\'active\')
                  AND (' . implode(' OR ', $conditions) . ')
                ORDER BY c.nombre COLLATE NOCASE ASC
                LIMIT ' . max(1, min($limit, 50));

        return Database::getInstance()->fetchAll($sql, $params);
    }

    /** @return list<array<string,mixed>> */
    public static function commercesForLocation(string $slug, int $limit = 24): array
    {
        $locations = self::locations();
        if (!isset($locations[$slug])) {
            return [];
        }

        $city = (string)($locations[$slug]['title'] ?? '');
        if ($city === '') {
            return [];
        }

        return Database::getInstance()->fetchAll(
            "SELECT c.slug, c.nombre, c.ciudad, r.nombre AS rubro_nombre
             FROM commerces c
             LEFT JOIN rubros r ON r.id_rubro = c.id_rubro
             WHERE c.status IN ('trial','active')
               AND lower(c.ciudad) LIKE lower(:city)
             ORDER BY c.nombre COLLATE NOCASE ASC
             LIMIT " . max(1, min($limit, 50)),
            [':city' => '%' . $city . '%']
        );
    }

    public static function jsonLdFaq(): string
    {
        $entities = [];
        foreach (self::faq() as $item) {
            $entities[] = [
                '@type' => 'Question',
                'name' => $item['q'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $item['a'],
                ],
            ];
        }
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entities,
        ];
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
