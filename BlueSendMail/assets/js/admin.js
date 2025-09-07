jQuery(document).ready(function($) {
    /**
     * Lógica para a página de configurações
     */
    if ($('#bsm_mailer_type').length) {
        function toggleMailerFields() {
            var mailerType = $('#bsm_mailer_type').val();
            $('.bsm-smtp-option').closest('tr').hide();
            $('.bsm-sendgrid-option').closest('tr').hide();

            if (mailerType === 'sendgrid') {
                $('.bsm-sendgrid-option').closest('tr').show();
            } else if (mailerType === 'smtp') {
                $('.bsm-smtp-option').closest('tr').show();
            }
        }
        toggleMailerFields();
        $('#bsm_mailer_type').on('change', toggleMailerFields);
    }

    /**
     * Lógica para a tela de edição de campanha
     */
    if (typeof bsm_admin_data !== 'undefined' && bsm_admin_data.is_campaign_editor) {
        // Inicializa o Select2 na lista de destinatários
        if ($('#bsm-lists-select').length) {
            $('#bsm-lists-select').select2({
                placeholder: "Selecione as listas de destinatários",
                allowClear: true
            });
        }

        // Lógica para o Agendamento
        var scheduleCheckbox = $('#bsm-schedule-enabled');
        var scheduleFields = $('#bsm-schedule-fields');
        var sendNowButton = $('#bsm-send-now-button');
        var scheduleButton = $('#bsm-schedule-button');

        function toggleScheduleUI() {
            if (scheduleCheckbox.is(':checked')) {
                scheduleFields.slideDown();
                sendNowButton.hide();
                scheduleButton.show();
            } else {
                scheduleFields.slideUp();
                sendNowButton.show();
                scheduleButton.hide();
            }
        }
        toggleScheduleUI();
        scheduleCheckbox.on('change', toggleScheduleUI);

        // Lógica para inserir Merge Tags no editor
        $('.bsm-merge-tag').on('click', function() {
            var tag = $(this).data('tag');
            
            if (typeof tinymce !== 'undefined' && tinymce.get('bsm-content') && !tinymce.get('bsm-content').isHidden()) {
                tinymce.get('bsm-content').execCommand('mceInsertContent', false, ' ' + tag + ' ');
            } else {
                var editor = $('#bsm-content');
                var currentVal = editor.val();
                var cursorPos = editor.prop('selectionStart');
                var newVal = currentVal.substring(0, cursorPos) + ' ' + tag + ' ' + currentVal.substring(cursorPos);
                editor.val(newVal);
                editor.focus();
                editor.prop('selectionStart', cursorPos + tag.length + 2);
                editor.prop('selectionEnd', cursorPos + tag.length + 2);
            }
        });
    }

    /**
     * Lógica para a página de importação (Passo 2)
     */
    if (typeof bsm_admin_data !== 'undefined' && bsm_admin_data.is_import_page) {
        // Tenta pré-selecionar os campos com base no nome do cabeçalho
        $('select[name^="column_map"]').each(function() {
            var labelText = $(this).closest('tr').find('th label').text().toLowerCase();
            if (labelText.includes('mail')) {
                $(this).val('email');
            } else if (labelText.includes('nome') || labelText.includes('first')) {
                $(this).val('first_name');
            } else if (labelText.includes('sobrenome') || labelText.includes('last') || labelText.includes('apelido')) {
                $(this).val('last_name');
            }
        });
    }

    /**
     * Lógica para a página de Relatórios
     */
    if (typeof bsm_admin_data !== 'undefined' && bsm_admin_data.is_reports_page && typeof bsm_admin_data.chart_data !== 'undefined') {
        var ctx = document.getElementById('bsm-report-chart');
        if (ctx) {
            var chartData = bsm_admin_data.chart_data;
            var notOpened = chartData.sent - chartData.opens;
            notOpened = Math.max(0, notOpened);
            var opens_only = chartData.opens - chartData.clicks;
            opens_only = Math.max(0, opens_only);

            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: [ chartData.labels.not_opened, chartData.labels.opened, chartData.labels.clicked ],
                    datasets: [{
                        label: 'Visão Geral da Campanha',
                        data: [notOpened, opens_only, chartData.clicks],
                        backgroundColor: [ 'rgb(220, 220, 220)', 'rgb(54, 162, 235)', 'rgb(75, 192, 192)' ],
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        title: { display: true, text: 'Desempenho da Campanha' }
                    }
                }
            });
        }
    }

    /**
     * Lógica para o Dashboard
     */
    if (typeof bsm_admin_data !== 'undefined' && bsm_admin_data.is_dashboard_page) {
        // Lógica para abas do ranking de engajamento
        $('.bsm-tab').on('click', function() {
            var tabId = $(this).data('tab');
            $('.bsm-tab').removeClass('active');
            $('.bsm-tab-content').removeClass('active');
            $(this).addClass('active');
            $('#bsm-tab-' + tabId).addClass('active');
        });

        // Lógica para o gráfico de crescimento de contatos
        if (typeof bsm_admin_data.growth_chart_data !== 'undefined') {
            var growthCtx = document.getElementById('bsm-growth-chart');
            if (growthCtx) {
                new Chart(growthCtx, {
                    type: 'line',
                    data: {
                        labels: bsm_admin_data.growth_chart_data.labels,
                        datasets: [{
                            label: 'Novos Contatos',
                            data: bsm_admin_data.growth_chart_data.data,
                            fill: true,
                            backgroundColor: 'rgba(54, 162, 235, 0.2)',
                            borderColor: 'rgb(54, 162, 235)',
                            tension: 0.1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });
            }
        }

        // Lógica para o gráfico de performance geral
        if (typeof bsm_admin_data.performance_chart_data !== 'undefined') {
            var perfCtx = document.getElementById('bsm-performance-chart');
            if (perfCtx) {
                var perfData = bsm_admin_data.performance_chart_data;
                var notOpened = perfData.sent - perfData.opens;
                notOpened = Math.max(0, notOpened);
                var opens_only = perfData.opens - perfData.clicks;
                opens_only = Math.max(0, opens_only);

                new Chart(perfCtx, {
                    type: 'doughnut',
                    data: {
                        labels: [ perfData.labels.not_opened, perfData.labels.opened, perfData.labels.clicked ],
                        datasets: [{
                            label: 'Performance Geral',
                            data: [notOpened, opens_only, perfData.clicks],
                            backgroundColor: [ 'rgb(220, 220, 220)', 'rgb(54, 162, 235)', 'rgb(75, 192, 192)' ],
                            hoverOffset: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                            },
                        }
                    }
                });
            }
        }
    }
});

