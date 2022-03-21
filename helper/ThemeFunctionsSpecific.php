<?php declare(strict_types=1);

namespace OmekaTheme\Helper;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;

trait ThemeFunctionsSpecific
{
    protected $accessLevels = [
        'free-access' => 0,
        'limited-access' => 1,
        'no-access' => 2,
    ];

    protected $classesAccess = [
        'Accès libre' => 'free-access',
        'Accès restreint' => 'limited-access',
        'Non consultable' => 'no-access', // Défaut.
    ];

    /**
     * Le type du document.
     *
     * Le type n'est pas forcément la classe, mais tout type, mais le formulaire ne le prévoit pas.
     */
    public function danteDocumentType(ItemRepresentation $resource): string
    {
        return $resource->displayResourceClassLabel('Travail étudiant');
    }

    /**
     * Toutes les métadonnées sont accessibles : le type d'accès est donc défini
     * par le média le plus libre.
     *
     * On ne regarde pas l'embargo ici : il est déjà pris en compte (mise en public).
     */
    public function danteAccess(AbstractResourceEntityRepresentation $resource): string
    {
        if ($resource instanceof ItemRepresentation) {
            // S'il n'y a pas de fichier, c'est qu'il est non consultable de fait.
            $medias = $resource->media();
            if (!count($medias)) {
                return 'Non consultable';
            }
            foreach ($medias as $media) {
                $access = $media->value('curation:access')
                    ?? ($media->value('curation:reserved') ? 'Accès restreint' : 'Accès libre');
                $code = $this->classesAccess[(string) $access] ?? 'no-access';
                $levels[] = $this->accessLevels[$code];
            }
            $level = min($levels);
            return array_search(array_search($level, $this->accessLevels), $this->classesAccess)
                ?: 'Non consultable';
        }

        $access = $resource->value('curation:access')
            ?? ($resource->value('curation:reserved') ? 'Accès restreint' : 'Accès libre');
        $code = $this->classesAccess[(string) $access] ?? 'no-access';
        return array_search($code, $this->classesAccess)
            ?: 'Non consultable';
    }

    /**
     * @var \Omeka\Api\Representation\ValueRepresentation|string $value
     */
    public function danteAccessClass($value): string
    {
        return $this->classesAccess[(string) $value] ?? 'no-access';
    }

    public function danteAuteur(ItemRepresentation $resource): string
    {
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

        return $name;
    }

    public function danteAnnee(ItemRepresentation $resource): string
    {
        $value = $resource->value('dcterms:created');
        if ($value) {
            $year = substr((string) $value, 0, 4);
        } else {
            $year = 'sans date';
        }
        return $year;
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

        $auteur = $this->danteAuteur($resource);
        $annee = $this->danteAnnee($resource);
        $titre = $resource->displayTitle('sans titre');

        if ($short) {
            return sprintf(
                '<span class="dcterms-creator">%s</span> (<span class="dcterms:created">%s</span>), <span class="document-title dcterms-title">%s</span>',
                $auteur, $escape($annee), $escape($titre)
            );
        }

        $documentType = $this->danteDocumentType($resource);
        return sprintf(
            '<span class="dcterms-creator">%s</span> (<span class="dcterms:created">%s</span>), <span class="document-title dcterms-title">%s</span> [<span class="dcterms-type">%s</span>]',
            $auteur, $escape($annee), $escape($titre), $escape($documentType)
        );
    }
}
