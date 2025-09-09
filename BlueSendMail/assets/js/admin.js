jQuery(function($) {
    'use strict';

    // Log de Diagnóstico: Confirma que o ficheiro admin.js foi carregado e está a ser executado.
    console.log('BlueSendMail: Ficheiro admin.js carregado com sucesso.');

    /**
     * Lógica para a página de configurações (Versão Robusta com Diagnóstico)
     */
    if ($('#bsm_mailer_type').length) {
        // Log de Diagnóstico: Confirma que o seletor do método de envio foi encontrado na página.
        console.log('BlueSendMail: Página de Configurações detetada. Iniciando lógica dos campos do disparador.');

        function toggleMailerFields() {
            var mailerType = $('#bsm_mailer_type').val();
            
            // Log de Diagnóstico: Mostra qual o método de envio selecionado.
            console.log('BlueSendMail: Método de envio alterado para: ' + mailerType);

            // Esconde todas as opções primeiro
            $('.bsm-smtp-option').closest('tr').hide();
            $('.bsm-sendgrid-option').closest('tr').hide();

            // Mostra as opções relevantes
            if (mailerType === 'smtp') {
                console.log('BlueSendMail: A mostrar campos SMTP.');
                $('.bsm-smtp-option').closest('tr').show();
            } else if (mailerType === 'sendgrid') {
                console.log('BlueSendMail: A mostrar campos SendGrid.');
                $('.bsm-sendgrid-option').closest('tr').show();
            }
        }
        
        // Executa a função no carregamento da página
        toggleMailerFields();
        
        // Adiciona o listener para futuras mudanças
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

        // Lógica para carregar o conteúdo do template
        $('.bsm-template-card').on('click', function(e){
            e.preventDefault();
            var $card = $(this);
            var templateId = $card.data('template-id');

            $('.bsm-template-card').removeClass('active');
            $card.addClass('active');

            var $editorContainer = $('#wp-bsm-content-wrap');
            $editorContainer.css('opacity', 0.5);

            $.ajax({
                url: bsm_admin_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'bsm_get_template_content',
                    template_id: templateId,
                    nonce: bsm_admin_data.nonce
                },
                success: function(response) {
                    if (response.success) {
                        if (typeof tinymce !== 'undefined' && tinymce.get('bsm-content')) {
                            tinymce.get('bsm-content').setContent(response.data.content);
                        } else {
                            $('#bsm-content').val(response.data.content);
                        }
                    } else {
                        alert('Erro ao carregar o template.');
                    }
                },
                error: function() {
                    alert('Erro de comunicação ao carregar o template.');
                },
                complete: function() {
                    $editorContainer.css('opacity', 1);
                }
            });
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
     * Lógica para os gráficos
     */
    function initializeChart(ctx, type, data, options) {
        if (ctx) {
            new Chart(ctx, {
                type: type,
                data: data,
                options: options,
            });
        }
    }

    // Gráfico de Relatórios
    if (typeof bsm_admin_data !== 'undefined' && bsm_admin_data.is_reports_page && typeof bsm_admin_data.chart_data !== 'undefined') {
        var reportCtx = document.getElementById('bsm-report-chart');
        var chartData = bsm_admin_data.chart_data;
        var notOpened = Math.max(0, chartData.sent - chartData.opens);
        var opens_only = Math.max(0, chartData.opens - chartData.clicks);
        
        initializeChart(reportCtx, 'doughnut', {
            labels: [ chartData.labels.not_opened, chartData.labels.opened, chartData.labels.clicked ],
            datasets: [{
                label: 'Visão Geral da Campanha',
                data: [notOpened, opens_only, chartData.clicks],
                backgroundColor: [ 'rgb(220, 220, 220)', 'rgb(54, 162, 235)', 'rgb(75, 192, 192)' ],
                hoverOffset: 4
            }]
        }, {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' },
                title: { display: true, text: 'Desempenho da Campanha' }
            }
        });
    }

    // Gráficos do Dashboard
    if (typeof bsm_admin_data !== 'undefined' && bsm_admin_data.is_dashboard_page) {
        // Gráfico de Crescimento
        var growthCtx = document.getElementById('bsm-growth-chart');
        if (typeof bsm_admin_data.growth_chart_data !== 'undefined') {
            initializeChart(growthCtx, 'line', {
                labels: bsm_admin_data.growth_chart_data.labels,
                datasets: [{
                    label: 'Novos Contatos',
                    data: bsm_admin_data.growth_chart_data.data,
                    fill: true,
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    borderColor: 'rgb(54, 162, 235)',
                    tension: 0.1
                }]
            }, {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
                plugins: { legend: { display: false } }
            });
        }

        // Gráfico de Performance Geral
        var perfCtx = document.getElementById('bsm-performance-chart');
        if (typeof bsm_admin_data.performance_chart_data !== 'undefined') {
            var perfData = bsm_admin_data.performance_chart_data;
            var perfNotOpened = Math.max(0, perfData.sent - perfData.opens);
            var perfOpensOnly = Math.max(0, perfData.opens - perfData.clicks);
            
            initializeChart(perfCtx, 'doughnut', {
                labels: [ perfData.labels.not_opened, perfData.labels.opened, perfData.labels.clicked ],
                datasets: [{
                    label: 'Performance Geral',
                    data: [perfNotOpened, perfOpensOnly, perfData.clicks],
                    backgroundColor: [ 'rgb(220, 220, 220)', 'rgb(54, 162, 235)', 'rgb(75, 192, 192)' ],
                    hoverOffset: 4
                }]
            }, {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } }
            });
        }
    }
});

