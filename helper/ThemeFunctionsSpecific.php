<?php declare(strict_types=1);

namespace OmekaTheme\Helper;

use Omeka\Api\Representation\ItemRepresentation;

trait ThemeFunctionsSpecific
{
    /**
     * @var \Omeka\Api\Representation\ValueRepresentation|string $value
     */
    public function danteAcces($value): string
    {
        $classes = [
            'Accès restreint' => 'limited-access',
            'Accès libre' => 'free-access',
            'Non consultable' => 'no-access', // Défaut.
        ];
        return $classes[(string) $value] ?? 'no-access';
    }

    /**
     * Get the html citation from an item.
     */
    public function danteCitation(ItemRepresentation $resource, bool $short = false): string
    {
        static $escape;

        if (is_null($escape)) {
            $escape = $this->view->plugin('escapeHtml');
        }

        if ($value = $resource->value('dcterms:creator')) {
            if ($vr = $value->valueResource()) {
                $familyName = $vr->value('foaf:familyName');
                $givenName = $vr->value('foaf:givenName');
                $name = ($familyName ? '<span class="foaf-familyName">' . $familyName->asHtml() . '</span>' : '')
                    . ($givenName ? ($familyName ? ', ' : '') . '<span class="foaf-givenName">' . $givenName->asHtml() . '</span>' : '');
                if (!$name) {
                    $name = $vr->value('foaf:name')->asHtml();
                }
            } else {
                $name = $value->asHtml();
            }
        } else {
            $name = 'Anonyme';
        }

        $value = $resource->value('dcterms:created');
        if ($value) {
            $year = substr((string) $value, 0, 4);
        } else {
            $year = 'sans date';
        }

        $title = $resource->displayTitle('sans titre');

        $citation = sprintf(
            '<span class="dcterms-creator">%s</span> (<span class="dcterms:created">%s</span>), <span class="document-title dcterms-title">%s</span>',
            $name, $escape($year), $escape($title)
        );

        return $citation;
    }
}
