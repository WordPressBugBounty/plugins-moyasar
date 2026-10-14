jQuery(document).ready(function ($) {
    // Selectors for Apple Pay settings
    const themeSelect = $('#woocommerce_moyasar_express_btn_theme');
    const heightInput = $('#woocommerce_moyasar_express_btn_height');
    const labelSelect = $('#woocommerce_moyasar_express_btn_label');
    const previewBtn = $('.moyasar-btn-preview .button');
    const previewBtnText = $('.moyasar-btn-preview .button .moyasar-apple-text');
    const previewBtnIcon = $('.moyasar-btn-preview .button .moyasar-apple-icon');

    function updatePreview() {
        // Theme
        const theme = themeSelect.val();
        if (theme === 'white') {
            previewBtn.css({
                'background-color': '#fff',
                'color': '#000',
                'border': '1px solid #dcdcde' // Minimal border for visibility
            });
        } else if (theme === 'white-outline') {
            previewBtn.css({
                'background-color': '#fff',
                'color': '#000',
                'border': '1px solid #000'
            });
        } else { // black or default
            previewBtn.css({
                'background-color': '#000',
                'color': '#fff',
                'border': 'none'
            });
        }

        // Height (Presets)
        let heightVal = heightInput.val();
        let heightPx = '44px'; // Default/Medium

        if (heightVal === 'small') {
            heightPx = '32px';
        } else if (heightVal === 'large') {
            heightPx = '55px';
        }

        previewBtn.css('height', heightPx);

        // Label
        const label = labelSelect.val();
        let text = 'Pay'; // default

        switch (label) {
            case 'plain': text = ''; break;
            case 'buy': text = 'Buy'; break;
            case 'donate': text = 'Donate'; break;
            case 'check-out': text = 'Check Out'; break;
            case 'book': text = 'Book'; break;
            case 'subscribe': text = 'Subscribe'; break;
            case 'reload': text = 'Reload'; break;
            case 'add-money': text = 'Add Money'; break;
            case 'top-up': text = 'Top Up'; break;
            case 'order': text = 'Order'; break;
            case 'rent': text = 'Rent'; break;
            case 'support': text = 'Support'; break;
            case 'contribute': text = 'Contribute'; break;
            case 'tip': text = 'Tip'; break;
            default: text = 'Pay';
        }

        if (text === '') {
            previewBtnText.hide();
            previewBtnIcon.css('margin-right', '0');
        } else {
            previewBtnText.text(text).show();
            // restore space
            previewBtnIcon.css('margin-right', '4px');
        }
    }

    // Bind events
    themeSelect.on('change', updatePreview);
    heightInput.on('input change', updatePreview);
    labelSelect.on('change', updatePreview);

    // Initial call
    updatePreview();
});

// Webhook Helpers
function moyasarCopyInput(id) {
    var copyText = document.getElementById(id);
    copyText.select();
    copyText.setSelectionRange(0, 99999); /* For mobile devices */

    try {
        var successful = document.execCommand('copy');
        var msg = successful ? 'Copied!' : 'Failed';

        // Find the button next to the input
        var btn = jQuery(copyText).next('button');
        var originalText = btn.text();
        btn.text(msg);
        setTimeout(function () {
            btn.text(originalText);
        }, 2000);
    } catch (err) {
        console.error('Fallback: Oops, unable to copy', err);
    }
}

function moyasarToggleSecret(id) {
    var x = document.getElementById(id);
    var btn = jQuery(x).next('button');

    // In PHP: Input -> Show -> Copy
    // Next to input is Show btn.

    if (x.type === "password") {
        x.type = "text";
        jQuery(btn).text('Hide');
    } else {
        x.type = "password";
        jQuery(btn).text('Show');
    }
}

// API Key Validation Handler
jQuery(document).ready(function ($) {
    var $validateBtn = $('#moyasar-validate-keys-btn');
    if (!$validateBtn.length) {
        return;
    }

    $validateBtn.on('click', function (e) {
        e.preventDefault();

        if (typeof moyasar_admin_params === 'undefined') {
            alert('Validation parameters are missing. Please refresh the page.');
            return;
        }

        var $btn = $(this);
        var $spinner = $('#moyasar-validate-spinner');
        var $summary = $('#moyasar-validate-summary');
        var $results = $('#moyasar-key-validation-results');

        // Field selectors
        var testPk = $('#woocommerce_moyasar_test_publishable_key').val();
        var testSk = $('#woocommerce_moyasar_test_secret_key').val();
        var livePk = $('#woocommerce_moyasar_live_publishable_key').val();
        var liveSk = $('#woocommerce_moyasar_live_secret_key').val();
        var testMode = $('#woocommerce_moyasar_testmode').is(':checked') ? 'yes' : 'no';

        // UI states
        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $summary.css('color', '#646970').text(moyasar_admin_params.strings.validating || 'Validating keys...');
        $('.moyasar-inline-key-badge').remove();

        $.ajax({
            url: moyasar_admin_params.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'moyasar_validate_keys',
                security: moyasar_admin_params.validate_keys_nonce,
                test_publishable_key: testPk,
                test_secret_key: testSk,
                live_publishable_key: livePk,
                live_secret_key: liveSk,
                testmode: testMode
            },
            success: function (response) {
                $spinner.removeClass('is-active');
                $btn.prop('disabled', false);

                if (response && response.success && response.data) {
                    var data = response.data;
                    renderValidationResults(data);
                } else {
                    var errorMsg = (response && response.data && response.data.message)
                        ? response.data.message
                        : (moyasar_admin_params.strings.connection_err || 'Validation failed.');
                    $summary.css('color', '#d63638').text(errorMsg);
                }
            },
            error: function () {
                $spinner.removeClass('is-active');
                $btn.prop('disabled', false);
                $summary.css('color', '#d63638').text(moyasar_admin_params.strings.connection_err || 'Connection error.');
            }
        });
    });

    function getBadgeHtml(status, label) {
        var cls = 'moyasar-badge-empty';
        var icon = 'dashicons-minus';

        if (status === 'valid') {
            cls = 'moyasar-badge-valid';
            icon = 'dashicons-yes';
        } else if (status === 'invalid') {
            cls = 'moyasar-badge-invalid';
            icon = 'dashicons-no';
        } else if (status === 'format_error') {
            cls = 'moyasar-badge-warning';
            icon = 'dashicons-warning';
        } else if (status === 'connection_error') {
            cls = 'moyasar-badge-warning';
            icon = 'dashicons-warning';
        }

        return '<span class="moyasar-key-badge ' + cls + '"><span class="dashicons ' + icon + '" style="font-size: 14px; vertical-align: middle; line-height: 1;"></span> ' + label + '</span>';
    }

    function renderValidationResults(data) {
        var $summary = $('#moyasar-validate-summary');
        var $results = $('#moyasar-key-validation-results');

        // Key mapping to DOM input IDs
        var fieldMap = {
            'test_publishable_key': { id: '#woocommerce_moyasar_test_publishable_key', label: 'Test Publishable Key' },
            'test_secret_key':      { id: '#woocommerce_moyasar_test_secret_key',      label: 'Test Secret Key' },
            'live_publishable_key': { id: '#woocommerce_moyasar_live_publishable_key', label: 'Live Publishable Key' },
            'live_secret_key':      { id: '#woocommerce_moyasar_live_secret_key',      label: 'Live Secret Key' }
        };

        // Render inline badges next to form table labels/inputs
        $.each(data.keys, function (key, item) {
            var fieldInfo = fieldMap[key];
            if (!fieldInfo) return;

            var $input = $(fieldInfo.id);
            if ($input.length) {
                var badgeLabel = moyasar_admin_params.strings[item.status] || item.status.toUpperCase();
                var badgeHtml = '<span class="moyasar-inline-key-badge" style="margin-left: 8px;">' + getBadgeHtml(item.status, badgeLabel) + '</span>';
                
                // Append after input or input container
                var $container = $input.closest('td');
                if ($container.length) {
                    $container.find('.moyasar-inline-key-badge').remove();
                    $container.append(badgeHtml);
                }
            }
        });

        // Summary text styling
        if (data.active_valid) {
            $summary.css('color', '#00a32a').html('<span class="dashicons dashicons-yes-alt" style="vertical-align: text-bottom;"></span> ' + data.summary);
        } else {
            $summary.css('color', '#d63638').html('<span class="dashicons dashicons-warning" style="vertical-align: text-bottom;"></span> ' + data.summary);
        }

        // Detailed Results Container HTML
        var resultsHtml = '<div class="moyasar-validation-card" style="border: 1px solid #dcdcde; border-radius: 6px; background: #fafafa; padding: 16px 20px; margin-top: 15px;">';
        
        // Active environment banner
        var bannerBg = data.active_valid ? '#f0fdf4' : '#fff1f2';
        var bannerBorder = data.active_valid ? '#bbf7d0' : '#fecdd3';
        var bannerColor = data.active_valid ? '#166534' : '#991b1b';
        var bannerIcon = data.active_valid ? 'dashicons-yes-alt' : 'dashicons-warning';

        resultsHtml += '<div style="background: ' + bannerBg + '; border: 1px solid ' + bannerBorder + '; color: ' + bannerColor + '; border-radius: 4px; padding: 10px 14px; margin-bottom: 15px; font-weight: 500; display: flex; align-items: center; gap: 8px;">';
        resultsHtml += '<span class="dashicons ' + bannerIcon + '"></span>';
        resultsHtml += '<span>' + data.summary + '</span>';
        resultsHtml += '</div>';

        // Table breakdown
        resultsHtml += '<table class="widefat striped" style="background: #fff; border: 1px solid #e5e5e5; border-radius: 4px; box-shadow: none;">';
        resultsHtml += '<thead><tr>';
        resultsHtml += '<th style="padding: 10px 14px;">' + ( 'API Key' ) + '</th>';
        resultsHtml += '<th style="padding: 10px 14px; width: 140px;">' + ( 'Status' ) + '</th>';
        resultsHtml += '<th style="padding: 10px 14px;">' + ( 'Validation Details' ) + '</th>';
        resultsHtml += '</tr></thead><tbody>';

        $.each(data.keys, function (key, item) {
            var fieldInfo = fieldMap[key];
            var isCurrentEnv = (data.test_mode && key.indexOf('test_') === 0) || (!data.test_mode && key.indexOf('live_') === 0);
            var envLabel = isCurrentEnv ? ' <span style="font-size: 10px; background: #2271b1; color: #fff; padding: 2px 6px; border-radius: 3px; font-weight: 600; text-transform: uppercase;">Active</span>' : '';
            var badgeLabel = moyasar_admin_params.strings[item.status] || item.status.toUpperCase();

            resultsHtml += '<tr>';
            resultsHtml += '<td style="padding: 10px 14px; font-weight: 600;">' + (fieldInfo ? fieldInfo.label : key) + envLabel + '</td>';
            resultsHtml += '<td style="padding: 10px 14px;">' + getBadgeHtml(item.status, badgeLabel) + '</td>';
            resultsHtml += '<td style="padding: 10px 14px; color: ' + (item.valid ? '#1d2327' : '#d63638') + ';">' + item.message + '</td>';
            resultsHtml += '</tr>';
        });

        resultsHtml += '</tbody></table>';
        resultsHtml += '</div>';

        $results.html(resultsHtml).slideDown(200);
    }
});

