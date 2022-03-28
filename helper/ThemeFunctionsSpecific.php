<?php declare(strict_types=1);

namespace OmekaTheme\Helper;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Entity\User;

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
    public function danteDocumentType(ItemRepresentation $resource, ?string $default = 'Travail étudiant'): string
    {
        return $resource->displayResourceClassLabel($default);
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

    public function danteUserAuteur(User $user): ?ItemRepresentation
    {
        static $author = false;

        if ($author !== false) {
            return $author;
        }

        $api = $this->view->api();

        // L'api ne permet pas la recherche sur le nom de la classe directement.
        $resourceClass = $api->searchOne('resource_classes', ['term' => 'foaf:Person'])->getContent();
        $author = $api->searchOne('items', [
            'resource_class_id' => $resourceClass->id(),
            // FIXME Utiliser l'email, pas le nom.
            'property' => [['property' => 'foaf:mbox', 'type' => 'eq', 'text' => $user->getEmail()]]
        ])->getContent();
        if (!$author) {
            $author = $api->searchOne('items', [
                'resource_class_id' => $resourceClass->id(),
                // FIXME Utiliser l'email, pas le nom.
                'property' => [['property' => 'foaf:name', 'type' => 'eq', 'text' => $user->getName()]]
            ])->getContent();
        }
        return $author;
    }

    public function danteUserName(User $user): string
    {
        $author = $this->danteUserAuteur($user);
        return $author ? $author->value('foaf:familyName', ['default' => $user->getName()]) : $user->getName();
    }

    public function contributionSteps($contribution = null, $etape = null, $fields = null): array
    {
        static $steps;

        if (isset($steps)) {
            return $steps;
        }

        $plugins = $this->view->getHelperPluginManager();
        $translate = $plugins->get('translate');
        $escapeAttr = $plugins->get('escapeHtmlAttr');

        $etape = (int) ($etape ?? $this->view->params()->fromQuery('etape'));
        if ($etape) {
            $step = $etape;
        } elseif (empty($contribution)) {
            $step = empty($fields) ? 1 : 2;
        } elseif (isset($fields)) {
            $step = empty($fields) ? 1 : 2;
        } else {
            $step = 4;
        }

        // Il y a forcément une contribution, sauf dans la première étape.
        return $steps = [
            'current' => $step,
            1 => [
                'title' => $translate('Type de document'),
                // Attention : étape 1 est un simple affichage s'il y a une contribution.
                // Sinon, il s'agit d'un bouton submit.
                'url' => $contribution ? $escapeAttr($contribution->url('edit') . '?etape=1') : '#',
            ],
            2 => [
                'title' => 'Détails',
                'url' => $contribution ? $escapeAttr($contribution->url('edit') . '?etape=2') : '#',
            ],
            3 => [
                'title' => 'Téléchargement',
                'url' => $contribution ? $escapeAttr($contribution->url('edit') . '?etape=3') : '#',
            ],
            4 => [
                'title' => 'Dépôt',
                'url' => $contribution ? $escapeAttr($contribution->url('view') . '?etape=4') : '#',
            ],
        ];
   }
}
