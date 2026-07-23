$(document).ready(function() {
    // Show/hide salesperson dropdown based on status selection in telecaller dashboard & my_leads
    $(document).on('change', '.status-selector', function() {
        var status = $(this).val();
        var leadId = $(this).data('lead-id');
        var dropdown = $('#sales-dropdown-' + leadId);
        
        if (status === 'Assigned to Sales') {
            dropdown.slideDown(200);
            dropdown.find('select').attr('required', true);
        } else {
            dropdown.slideUp(200);
            dropdown.find('select').attr('required', false);
            dropdown.find('select').val('');
        }
    });

    // Auto-hide success/error messages after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // View History Modal Logic
    $(document).on('click', '.view-history-btn', function() {
        var leadId = $(this).data('lead-id');
        var historyModalEl = document.getElementById('historyModal');
        if (historyModalEl) {
            var modal = new bootstrap.Modal(historyModalEl);
            
            $('#historyLoader').show();
            $('#historyContent').hide().empty();
            modal.show();

            $.get('api/get_notes.php', { lead_id: leadId }, function(data) {
                var notes = [];
                try {
                    notes = typeof data === 'object' ? data : JSON.parse(data);
                } catch(e) {
                    notes = [];
                }
                var html = '';
                
                if (notes.length === 0) {
                    html = '<div class="text-center text-muted py-3">No history/notes found for this lead.</div>';
                } else {
                    html = '<div class="list-group list-group-flush">';
                    notes.forEach(function(note) {
                        var date = new Date(note.created_at).toLocaleString();
                        var badge = note.role === 'admin' ? 'bg-danger' : (note.role === 'telecaller' ? 'bg-info' : 'bg-success');
                        
                        html += '<div class="list-group-item px-0 py-3 border-bottom">';
                        html += '<div class="d-flex justify-content-between mb-1">';
                        html += '<small class="fw-semibold">' + (note.user_name || 'User') + ' <span class="badge ' + badge + ' ms-1" style="font-size: 10px;">' + note.role + '</span></small>';
                        html += '<small class="text-muted" style="font-size: 11px;">' + date + '</small>';
                        html += '</div>';
                        html += '<p class="mb-0 text-muted small">' + note.note + '</p>';
                        html += '</div>';
                    });
                    html += '</div>';
                }
                
                $('#historyLoader').hide();
                $('#historyContent').html(html).fadeIn();
            });
        }
    });

    // Show/hide deal value or lost reason in close deal modal
    $(document).on('change', '.outcome-selector', function() {
        var status = $(this).val();
        var leadId = $(this).data('lead-id');
        var valueGroup = $('#deal-value-group-' + leadId);
        var reasonGroup = $('#dead-reason-group-' + leadId);
        
        if (status === 'Converted') {
            valueGroup.slideDown(200);
            valueGroup.find('input').attr('required', true);
            reasonGroup.slideUp(200);
            reasonGroup.find('select').attr('required', false).val('');
        } else if (status === 'Dead') {
            valueGroup.slideUp(200);
            valueGroup.find('input').attr('required', false).val('');
            reasonGroup.slideDown(200);
            reasonGroup.find('select').attr('required', true);
        }
    });
});
