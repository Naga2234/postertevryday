(function ($) {
    $(function () {
        if (typeof cnapAdmin === 'undefined') {
            return;
        }

        var $loading = $('#cnap-loading');
        var $result = $('#cnap-result');

        function handleError() {
            alert(cnapAdmin.messages.toggleError);
        }

        function postAction(action) {
            return $.post(cnapAdmin.ajaxUrl, {
                action: action,
                nonce: cnapAdmin.nonce
            });
        }

        $('.cnap-action').on('click', function () {
            var actionType = $(this).data('action');
            if (!actionType) {
                return;
            }

            if (actionType === 'toggle') {
                postAction('cnap_toggle')
                    .done(function (response) {
                        if (response && response.data) {
                            alert(response.data);
                        }
                        location.reload();
                    })
                    .fail(handleError);
                return;
            }

            if (actionType === 'fetch') {
                $loading.css('display', 'flex');
                $result.hide();

                postAction('cnap_fetch')
                    .done(function (response) {
                        $loading.hide();
                        if (response && response.data) {
                            $result.html(response.data).slideDown();
                        }
                        setTimeout(function () {
                            location.reload();
                        }, 3000);
                    })
                    .fail(function () {
                        $loading.hide();
                        handleError();
                    });
            }
        });
    });
})(jQuery);
