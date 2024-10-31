// Initialize immediately
(function ($) {
    // Action to display the active tiered pricing table per variation
    $(".single_variation_wrap").on("show_variation", function (event, variation) {
        // remove active class from siblings
        $(".nfs_catalog_plugin_productbid").removeClass("active");
        $(".nfs_catalog_plugin_wrapper").removeClass("active");

        // add active class to selected variable product
        $(".nfs_catalog_plugin_productbid." + variation.sku).addClass("active");
        $(".nfs_catalog_plugin_wrapper." + variation.sku).addClass("active");
    });
})(jQuery);