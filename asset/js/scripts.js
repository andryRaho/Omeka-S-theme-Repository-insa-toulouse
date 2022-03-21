$(function () {

    const body = $('body');

    const burgerBtn = $('.open-header-menu');
    const headerBottomMenu = $('.header-bottom');

    const headerSearchBtn = $('.search-menu-toggle');
    const headerTopMenu = $('.header-top .search-form-parent');

    const documentListFilterToggle = $('.content a.resources-list-toggle-filters');
    const documentListFilterMenu = $('main.resources-list-and-filters aside');
    const documentListCloseFilterMenu = $('main.resources-list-and-filters aside .resources-list-close-filters');
    const documentListFilterCheckboxes = $('main.resources-list-and-filters aside input[type=checkbox]');

    const openSciencePageToggle = $('.content a.resources-list-toggle-page-menu');
    const openSciencePageMenu = $('main.open-science-content aside');
    const openScienceCloseMenu = $('main.open-science-content aside .resources-list-close-page-menu');

    const advancedSearchForm = $('.advanced-search-form');
    const advancedSearchTemplate = $('.advanced-search-form > .search-filters > li:first-child');
    const advancedSearchTemplateTarget = $('.advanced-search-form > .more-filters > .search-filters');

    const addDocumentToDepotBtn = $('.add-document-to-depot-btn');
    const depotContent = $('.depot-content');
    const depotContentEtape1 = $('.depot-content ul.navigation.etapes-depot li:nth-child(1) a');
    const depotContentEtape2 = $('.depot-content ul.navigation.etapes-depot li:nth-child(2) a');
    const depotContentEtape3 = $('.depot-content ul.navigation.etapes-depot li:nth-child(3) a');
    const depotContentEtape4 = $('.depot-content ul.navigation.etapes-depot li:nth-child(4) a');
    const navigationDepotSuivant = $('.depot-precedent-suivant li:last-child a');
    const navigationDepotPrecedent = $('.depot-precedent-suivant li:first-child a');

    depotContentEtape1.on('click', function(e) {
        depotContent.removeClass('depot-form-1 depot-form-2 depot-form-3 depot-form-4').addClass('depot-form-1');
    });

    depotContentEtape2.on('click', function(e) {
        depotContent.removeClass('depot-form-1 depot-form-2 depot-form-3 depot-form-4').addClass('depot-form-2');
    });

    depotContentEtape3.on('click', function(e) {
        depotContent.removeClass('depot-form-1 depot-form-2 depot-form-3 depot-form-4').addClass('depot-form-3');
    });

    depotContentEtape4.on('click', function(e) {
        depotContent.removeClass('depot-form-1 depot-form-2 depot-form-3 depot-form-4').addClass('depot-form-4');
    });

    navigationDepotSuivant.on('click', function(e) {
        e.preventDefault();
        if (depotContent.hasClass('depot-form-1')) {
            depotContent.removeClass('depot-form-1').addClass('depot-form-2');
        } else if (depotContent.hasClass('depot-form-2')) {
            depotContent.removeClass('depot-form-2').addClass('depot-form-3');
        } else if (depotContent.hasClass('depot-form-3')) {
            depotContent.removeClass('depot-form-3').addClass('depot-form-4');
        }
    });

    navigationDepotPrecedent.on('click', function(e) {
        e.preventDefault();
        if (depotContent.hasClass('depot-form-2')) {
            depotContent.removeClass('depot-form-2').addClass('depot-form-1');
        } else if (depotContent.hasClass('depot-form-3')) {
            depotContent.removeClass('depot-form-3').addClass('depot-form-2');
        } else if (depotContent.hasClass('depot-form-4')) {
            depotContent.removeClass('depot-form-4').addClass('depot-form-3');
        }
    });

    burgerBtn.on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        headerBottomMenu.toggleClass('opened');
        headerTopMenu.removeClass('opened');
    });

    headerSearchBtn.on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        headerTopMenu.toggleClass('opened');
        headerBottomMenu.removeClass('opened');
    });

    body.on('click', function(e) {
        headerBottomMenu.removeClass('opened');
        headerTopMenu.removeClass('opened');
        documentListFilterMenu.removeClass('opened');
        openSciencePageMenu.removeClass('opened');
    });

    headerBottomMenu.on('click', function(e) {
        e.stopPropagation();
    });

    headerTopMenu.on('click', function(e) {
        e.stopPropagation();
    });

    /* */

    const seeAllDocumentsLimit = 10;

    let documentsParents = $('ul.resources-list > li');
    documentsParents.each(function(no, item) {
        const documentItem = $(item);
        if (!documentItem.hasClass('resources-list-more-link')) {
            if (no + 1 > seeAllDocumentsLimit) {
                documentItem.addClass('displayed-with-toggle')
            }
        }
    });

    $('.resources-list-more-link a').on('click', function(e) {
        e.preventDefault();
        $(this).closest('ul').toggleClass('opened');
    });

    $('a.duplicate-fields-button').on('click', function(e) {
        e.preventDefault();

        const divParent = $(this).parent();
        const liParent = divParent.parent();
        const newPartIndex = liParent.find('.field-copy').length + 1;

        const cloned = liParent.find('.field-template').clone();
        cloned.removeClass('field-template').addClass('field-copy');

        $('input', cloned).val('');
        $('input', cloned).each(function(no, item) {
            const $item = $(item);
            const attrName = $item.attr('name');
            if (attrName.length) {
                $item.attr('name', attrName.split('[0]').join('['+ newPartIndex +']'))
            }
        });

        divParent.before(cloned)
    });

    /* Document-part : duplique les champs du fichier uploadé */

    addDocumentToDepotBtn.on('click', function(e) {
        e.preventDefault();

        const divParent = $(this).closest('form').find('.document-parts-forms');
        const newPartIndex = divParent.children().length;

        console.log('newPartIndex = ', newPartIndex);

        const cloned = divParent.find('.document-part-template').clone();
        cloned.removeClass('document-part-template');

        $('input', cloned).val('');
        $('input', cloned).each(function(no, item) {
            const $item = $(item);
            const attrName = $item.attr('name');
            if (attrName.length) {
                $item.attr('name', attrName.split('[0]').join('['+ newPartIndex +']'))
            }
        });

        divParent.append(cloned)
    });

    /* */

    openSciencePageToggle.on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        openSciencePageMenu.toggleClass('opened');
    });

    openScienceCloseMenu.on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        openSciencePageMenu.removeClass('opened');
    });

    openSciencePageMenu.on('click', function(e) {
        e.stopPropagation();
    });

    /* */

    documentListFilterToggle.on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        documentListFilterMenu.toggleClass('opened');
    });

    documentListCloseFilterMenu.on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        documentListFilterMenu.removeClass('opened');
    });

    documentListFilterMenu.on('click', function(e) {
        e.stopPropagation();
    });

    documentListFilterCheckboxes.on('change', function(e) {
        // Checkbox principal :
        const labelParent = $(e.target).closest('label');
        const liInput = labelParent.children('input[type=checkbox]');

        // Checkbox secondaires éventuels : on applique la valeur du checkbox principal
        const liParent = $(e.target).closest('li');
        const childrenCheckboxes = liParent.children('ul').find('input[type=checkbox]');
        childrenCheckboxes.prop('checked', liInput.is(':checked'));
    });

    /* */

    $('.toggle').on('click', function(e) {
        e.preventDefault();
        $(this).closest('.toggle-parent').toggleClass('opened');
    });

    /* */

    const seeAllFacetsLimit = 6;
    const seeMore = "+ Voir plus";
    const seeLess = "- Voir moins";

    const seeMoreHtml = '<span class="see-all-closed">' + seeMore + '</span><span class="see-all-opened">' + seeLess + '</span>';

    let facetsParents = $('ul.search-facet-items');
    facetsParents.each(function(no, item) {
        const facetParent = $(item);
        const facets = $('> li', facetParent);
        if (facets.length > seeAllFacetsLimit) {
            facetParent.addClass('with-toggle');
            facetParent.append('<li class="see-all-facets"><a href="#">'+ seeMoreHtml +'</a></li>');
            facets.each(function(no, item) {
                if (no + 1 > seeAllFacetsLimit) {
                    $(item).addClass('displayed-with-toggle')
                }
            });
        }
    });

    facetsParents.on('click', function(event) {
        const target = $(event.target);
        if (target.closest('li').hasClass('see-all-facets')) {
            event.preventDefault();
            $(this).closest('.search-facet-items').toggleClass('facets-toggle-opened');
        }
    });


    /* Advanced Search popup */

    $('a.advanced-search-link').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        body.addClass('advanced-search-popup-opened');
    });

    $('.advanced-search-close a').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        body.removeClass('advanced-search-popup-opened');
    });

    $('a.clear-button').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $('li', advancedSearchTemplateTarget).each(function(no, item) {
            // On laisse le premier
            if (no > 0) {
                $(item).remove();
            }
        });
    });

    advancedSearchForm.on('click', function(e) {
        e.stopPropagation();
        const target = $(e.target);
        if (target.parent().hasClass('advanced-search-more')) {
            e.preventDefault();
            const clone = advancedSearchTemplate.clone();
            $('input', clone).val('');
            advancedSearchTemplateTarget.append(clone);
        }
    });


    /* Limited access popup */

    $('.ask-copy-button').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        body.addClass('limited-access-popup-opened');
    });

    $('.limited-access-close a').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        body.removeClass('limited-access-popup-opened');
    });


    /* Resize */

    const searchResultsTools = $('.search-results-tools > li:first-child');
    const documentListTools = $('.resources-list-tools');

    function resizeUpdates() {
        if (searchResultsTools.length) {
            let margin = searchResultsTools.css('margin-left').split('px').join('');
            margin = (parseInt(margin) + 10) + 'px';
            documentListTools.css('padding-left', margin);
            documentListTools.css('padding-right', margin);
        }

    }

    $(window).on('resize', resizeUpdates);
    resizeUpdates();

});
