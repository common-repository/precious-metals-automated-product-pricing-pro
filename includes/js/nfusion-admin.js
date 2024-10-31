jQuery.noConflict();
// Wait for DOM
jQuery(document).ready(function($) {
    // Set timeout to hide messages after 5 seconds
    setTimeout(function() {
        $('#setting-error-nfusion_message').fadeOut('slow');
    }, 5000);

    // Initialize tabs
    $('#nfusion-tabs').tabs();

    // Retrieve and set active tab from localStorage
    var activeTab = localStorage.getItem('nfusion_active_tab');
    if (activeTab) {
        $('#nfusion-tabs').tabs('option', 'active', activeTab);
    }

    // Save active tab to localStorage when tab changes
    $('#nfusion-tabs').on('tabsactivate', function(event, ui) {
        var activeIndex = ui.newTab.index();
        localStorage.setItem('nfusion_active_tab', activeIndex);
    });

    // Init bootstrap toasts
    const toastElList = document.querySelectorAll('.toast')
    const toastList = [...toastElList].map(toastEl => new bootstrap.Toast(toastEl))

    // Init bootstrap tooltips
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]')
    const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl))


    // Clear nFusion product catalog cache
    $('.wrap .clear-cache[value=clear_cache]').click(function () {
        let currency = $(this).attr('currency');
        if (!currency) {
            currency = 'USD';
        }

        let transients = ['nfs_catalog_products_all_' + currency, 'nfs_catalog_products_all_secondary_' + currency, 'nfs_catalog_request_semaphore_' + currency,];

        $.ajax({
            type: 'POST',
            url: nfObj.ajaxurl,
            data: {
                'action': 'cleartransient',
                'transients': transients
            },
            success: function (response) {
                // Parse the JSON response
                let responseObject = JSON.parse(response);

                // Loop through the transients in the response
                Object.keys(responseObject.transients).forEach(function(transient) {
                    let transientData = responseObject.transients[transient];
                    let status, msg;

                    if(!transientData.productsMap_before_clear){
                        status = "warning";
                        msg = "cache not found.";
                    } else {
                        if (transientData.cleared) {
                            status = "success";
                            msg = "successfully cleared!"
                        } else {
                            status = "danger";
                            msg = "failed to clear cache!"
                        }
                    }

                    // Create a new toast element
                    let toast = `
                        <div class="toast nfusion-toast" role="alert" aria-live="assertive" aria-atomic="true">
                            <div class="toast-header bg-${status} text-dark p-2 bg-opacity-50">
                                <strong class="me-auto">nFusion Solutions</strong>
                                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                            </div>
                            <div class="toast-body">
                                <b>${transientData.name}</b>: ${msg}<br>
                                <b>Cache age</b>: ${transientData.age}
                            </div>
                        </div>`;

                    // Append the toast to the toast container
                    $('.toast-container').append(toast);
                });

                // Dispose of old toasts
                const myToastEls = document.querySelectorAll('.nfusion-toast');
                myToastEls.forEach((toast) => {
                    toast.addEventListener('hidden.bs.toast', () => {
                        // Dispose of the toast
                        toast.parentNode.removeChild(toast);
                    });
                });

                // Trigger Bootstrap toasts to be shown
                $('.nfusion-toast').toast('show');
            }
        })
    });
});