{strip}
{if false}
<!--suppress CssUnusedSymbol -->
{/if}
<style type="text/css">

{if !empty($customHeadingFont) && !empty($customHeadingFontName)}
@font-face {ldelim}
    font-family: '{$customHeadingFontName}';
    src: url('/fonts/{$customHeadingFont}');
{rdelim}
{elseif $headingFont}
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family={$headingFont}">
{/if}
{if !empty($customBodyFont) && !empty($customBodyFontName)}
@font-face {ldelim}
    font-family: '{$customBodyFontName}';
    src: url('/fonts/{$customBodyFont}');
{rdelim}
{elseif $bodyFont}
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family={$bodyFont}">
{/if}

{if $headingFont}
h1, h2, h3, h4, h5, .header-button, .menu-bar-label, .panel-title, label,.browse-category,#browse-sub-category-menu,button,
.btn,.myAccountLink,.adminMenuLink,.selected-browse-label-search,.result-label,.result-title,.label,#remove-search-label,#narrow-search-label,#library-name-header{ldelim}
    font-family: "{$headingFont}", "Helvetica Neue", Helvetica, Arial, sans-serif;
{rdelim}
{/if}
{if $bodyFont}
body{ldelim}
    font-family: "{$bodyFont}", "Helvetica Neue", Helvetica, Arial, sans-serif;
{rdelim}
{/if}

#header-container{ldelim}
    background-color: {$headerBackgroundColor};
    background-image: none;
    color: {$headerForegroundColor};
    {if $headerBottomBorderWidth}
        border-bottom-width: {$headerBottomBorderWidth};
    {/if}
{rdelim}

#library-name-header{ldelim}
    color: {$headerForegroundColor};
{rdelim}

#footer-container{ldelim}
    background-color: {$footerBackgroundColor};
    color: {$footerForegroundColor};
{rdelim}

.header-button{ldelim}
    background-color: {$headerButtonBackgroundColor};
    color: {$headerButtonColor} !important;
    {if $headerButtonRadius}
        border-radius: {$headerButtonRadius};
    {/if}
{rdelim}
#home-page-login{ldelim}
    background-color: {$headerButtonBackgroundColor};
    color: {$headerButtonColor} !important;
{rdelim}
#home-page-login a{ldelim}
    color: {$headerButtonColor} !important;
{rdelim}

body {ldelim}
    background-color: {$pageBackgroundColor};
    color: {$bodyTextColor};
{rdelim}

a,.result-head,#selected-browse-label a{ldelim}
    color: {$linkColor};
{rdelim}

body .container, #home-page-browse-content{ldelim}
    background-color: {$bodyBackgroundColor};
    color: {$bodyTextColor};
{rdelim}

#selected-browse-label{ldelim}
    background-color: {$bodyBackgroundColor};
{rdelim}

#home-page-search, #horizontal-search-box, #explore-more-sidebar,.searchTypeHome,.searchSource,.menu-bar,#vertical-menu-bar {ldelim}
    background-color: {$primaryBackgroundColor};
    color: {$primaryForegroundColor};
{rdelim}
#vertical-menu-bar .menu-icon .menu-bar-label, #horizontal-search-label,#horizontal-search-box #horizontal-search-label {ldelim}
    color: {$primaryForegroundColor};
{rdelim}

#vertical-menu-bar .menu-bar-option.menu-icon-selected,.exploreMoreBar .label-top, .exploreMoreBar .label-top img{ldelim}
    background-color: {$sidebarHighlightBackgroundColor};
    color: {$sidebarHighlightForegroundColor};
{rdelim}
#vertical-menu-bar .menu-bar-option.menu-icon-selected .menu-bar-label,#vertical-menu-bar .menu-icon:hover .menu-bar-label,.exploreMoreBar .exploreMoreBarLabel{ldelim}
    color: {$sidebarHighlightForegroundColor};
{rdelim}
.exploreMoreBar{ldelim}
    border-color: {$primaryBackgroundColor};
{rdelim}
#vertical-menu-bar .menu-bar-option:hover{ldelim}
    background-color: {$sidebarHighlightBackgroundColor};
    color: {$sidebarHighlightForegroundColor};
{rdelim}

{if $primaryForegroundColor}
#home-page-search-label,#home-page-advanced-search-link,#keepFiltersSwitchLabel, #advancedSearchLink,.menu-bar,#vertical-menu-bar{ldelim}
    color: {$primaryForegroundColor}
{rdelim}
{/if}

.facetTitle, .exploreMoreTitle,.panel-default > .panel-heading, .sidebar-links .panel-heading, #account-link-accordion .panel .panel-title, #account-settings-accordion .panel .panel-title{ldelim}
    background-color: {$closedPanelBackgroundColor};
{rdelim}
.facetTitle, .exploreMoreTitle,.panel-title,.panel-default > .panel-heading, .sidebar-links .panel-heading, #account-link-accordion .panel .panel-title, #account-settings-accordion .panel .panel-title, .panel-title > a,.panel-default > .panel-heading{ldelim}
    color: {$closedPanelForegroundColor};
{rdelim}
.facetTitle.expanded, .exploreMoreTitle.expanded,#more-details-accordion .active .panel-heading,.active .panel-default > .panel-heading, .sidebar-links .active .panel-heading, #account-link-accordion .panel.active .panel-title, #account-settings-accordion .panel.active .panel-title,.active .panel-title,.active .panel-title > a,.active.panel-default > .panel-heading{ldelim}
    background-color: {$openPanelBackgroundColor};
{rdelim}
.facetTitle.expanded, .exploreMoreTitle.expanded,#more-details-accordion .active .panel-heading,#more-details-accordion .active .panel-title,#account-link-accordion .panel.active .panel-title,.active .panel-title,.active .panel-title > a,.active.panel-default > .panel-heading{ldelim}
    color: {$openPanelForegroundColor};
{rdelim}
.panel-body,.sidebar-links .panel-body,#more-details-accordion .panel-body,.facetDetails,.sidebar-links .panel-body a:not(.btn), .sidebar-links .panel-body a:visited:not(.btn), .sidebar-links .panel-body a:hover:not(.btn){ldelim}
    background-color: {$panelBodyBackgroundColor};
    color: {$panelBodyForegroundColor};
{rdelim}

#footer-container{ldelim}
    border-top-color: {$tertiaryBackgroundColor};
{rdelim}
#header-container{ldelim}
    border-bottom-color: {$tertiaryBackgroundColor};
{rdelim}

#vertical-menu-bar .menu-bar-option.menu-icon-selected,#vertical-menu-bar .menu-bar-option:hover{ldelim}
    background-color: {$sidebarHighlightBackgroundColor};
    color: {$sidebarHighlightForegroundColor};
{rdelim}

{* Browse Categories *}
#home-page-browse-header{ldelim}
    background-color: {$browseCategoryPanelColor};
{rdelim}

.browse-category,#browse-sub-category-menu button{ldelim}
    background-color: {$deselectedBrowseCategoryBackgroundColor} !important;
    border-color: {$deselectedBrowseCategoryBorderColor} !important;
    color: {$deselectedBrowseCategoryForegroundColor} !important;
{rdelim}

.browse-category.selected,.browse-category.selected:hover,#browse-sub-category-menu button.selected,#browse-sub-category-menu button.selected:hover{ldelim}
    border-color: {$selectedBrowseCategoryBorderColor} !important;
    background-color: {$selectedBrowseCategoryBackgroundColor} !important;
    color: {$selectedBrowseCategoryForegroundColor} !important;
{rdelim}

{if !empty($capitalizeBrowseCategories)}
.browse-category div{ldelim}
    text-transform: uppercase;
{rdelim}
{/if}

{if !empty($buttonRadius)}
.btn{ldelim}
    border-radius: {$buttonRadius};
{rdelim}
{/if}

{if !empty($smallButtonRadius)}
.btn-sm{ldelim}
    border-radius: {$smallButtonRadius};
{rdelim}
{/if}

.btn-default{ldelim}
    background-color: {$defaultButtonBackgroundColor};
    color: {$defaultButtonForegroundColor};
    border-color: {$defaultButtonBorderColor};
{rdelim}

.btn-default:hover, .btn-default:focus, .btn-default:active, .btn-default.active, .open .dropdown-toggle.btn-default{ldelim}
    background-color: {$defaultButtonHoverBackgroundColor};
    color: {$defaultButtonHoverForegroundColor};
    border-color: {$defaultButtonHoverBorderColor};
{rdelim}

.btn-primary{ldelim}
    background-color: {$primaryButtonBackgroundColor};
    color: {$primaryButtonForegroundColor};
    border-color: {$primaryButtonBorderColor};
{rdelim}

.btn-primary:hover, .btn-primary:focus, .btn-primary:active, .btn-primary.active, .open .dropdown-toggle.btn-primary{ldelim}
    background-color: {$primaryButtonHoverBackgroundColor};
    color: {$primaryButtonHoverForegroundColor};
    border-color: {$primaryButtonHoverBorderColor};
{rdelim}

.btn-action{ldelim}
    background-color: {$actionButtonBackgroundColor};
    color: {$actionButtonForegroundColor};
    border-color: {$actionButtonBorderColor};
{rdelim}

.btn-action:hover, .btn-action:focus, .btn-action:active, .btn-action.active, .open .dropdown-toggle.btn-action{ldelim}
    background-color: {$actionButtonHoverBackgroundColor};
    color: {$actionButtonHoverForegroundColor};
    border-color: {$actionButtonHoverBorderColor};
{rdelim}

.btn-info{ldelim}
    background-color: {$infoButtonBackgroundColor};
    color: {$infoButtonForegroundColor};
    border-color: {$infoButtonBorderColor};
{rdelim}

.btn-info:hover, .btn-info:focus, .btn-info:active, .btn-info.active, .open .dropdown-toggle.btn-info{ldelim}
    background-color: {$infoButtonHoverBackgroundColor};
    color: {$infoButtonHoverForegroundColor};
    border-color: {$infoButtonHoverBorderColor};
{rdelim}

.btn-warning{ldelim}
    background-color: {$warningButtonBackgroundColor};
    color: {$warningButtonForegroundColor};
    border-color: {$warningButtonBorderColor};
{rdelim}

.btn-warning:hover, .btn-warning:focus, .btn-warning:active, .btn-warning.active, .open .dropdown-toggle.btn-warning{ldelim}
    background-color: {$warningButtonHoverBackgroundColor};
    color: {$warningButtonHoverForegroundColor};
    border-color: {$warningButtonHoverBorderColor};
{rdelim}

.label-warning{ldelim}
    background-color: {$warningButtonBackgroundColor};
    color: {$warningButtonForegroundColor};
{rdelim}

.btn-danger{ldelim}
    background-color: {$dangerButtonBackgroundColor};
    color: {$dangerButtonForegroundColor};
    border-color: {$dangerButtonBorderColor};
{rdelim}

.btn-danger:hover, .btn-danger:focus, .btn-danger:active, .btn-danger.active, .open .dropdown-toggle.btn-danger{ldelim}
    background-color: {$dangerButtonHoverBackgroundColor};
    color: {$dangerButtonHoverForegroundColor};
    border-color: {$dangerButtonHoverBorderColor};
{rdelim}

.label-danger{ldelim}
    background-color: {$dangerButtonBackgroundColor};
    color: {$dangerButtonForegroundColor};
{rdelim}

.btn-editions{ldelim}
    background-color: {$editionsButtonBackgroundColor};
    color: {$editionsButtonForegroundColor};
    border-color: {$editionsButtonBorderColor};
{rdelim}

.btn-editions:hover, .btn-editions:focus, .btn-editions:active, .btn-editions.active{ldelim}
    background-color: {$editionsButtonHoverBackgroundColor};
    color: {$editionsButtonHoverForegroundColor};
    border-color: {$editionsButtonHoverBorderColor};
{rdelim}

.badge{ldelim}
    background-color: {$badgeBackgroundColor};
    color: {$badgeForegroundColor};
    {if (!empty($badgeBorderRadius))}
    border-radius: {$badgeBorderRadius};
    {/if}
{rdelim}

{$additionalCSS}
</style>
{/strip}