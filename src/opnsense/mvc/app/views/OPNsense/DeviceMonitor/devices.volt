<div class="content-box">
    <div class="content-box-main">

        <!-- Header s verzí a statistikami -->
        <div style="padding:10px 10px 8px 10px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;border-bottom:1px solid #333;margin-bottom:12px;">
            <h1 style="margin:0;font-size:20px;">
                {{ lang._('Device Monitor') }}
                <small id="plugin-version" style="font-size:13px;color:#888;margin-left:5px;"></small>
                <span style="color:#555;margin:0 8px;">–</span>
                <span style="font-weight:normal;">{{ lang._('Devices') }}</span>
            </h1>
            <div style="display:flex;gap:20px;align-items:center;">
                <span style="font-size:13px;color:#888;">
                    {{ lang._('Total Devices') }}:
                    <strong id="stat-total" style="color:#ccc;font-size:16px;margin-left:4px;">—</strong>
                </span>
                <span style="font-size:13px;color:#888;">
                    {{ lang._('Online') }}:
                    <strong id="stat-online" style="color:#4CAF50;font-size:16px;margin-left:4px;">—</strong>
                </span>
            </div>
        </div>

        <!-- Toolbar -->
        <div style="padding:0 4px 12px 4px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">

            <!-- Multi-select VLAN dropdown -->
            <div class="dropdown" id="vlan-filter-wrapper" style="display:inline-block;">
                <button type="button" class="btn btn-default dropdown-toggle"
                        id="vlan-dropdown-toggle" data-toggle="dropdown"
                        style="min-width:160px;text-align:left;">
                    <span id="vlan-filter-label">{{ lang._('All interfaces') }}</span>
                    <span class="caret" style="float:right;margin-top:7px;"></span>
                </button>
                <ul class="dropdown-menu" id="vlan-checklist"
                    style="min-width:240px;padding:4px 0;max-height:300px;overflow-y:auto;">
                </ul>
            </div>

            <!-- Status filtr -->
            <select id="filter-status" class="form-control" style="width:auto;min-width:130px;">
                <option value="">{{ lang._('All statuses') }}</option>
                <option value="online">🟢 Online</option>
                <option value="offline">⚫ Offline</option>
            </select>

            <!-- Rezervace filtr -->
            <select id="filter-reserved" class="form-control" style="width:auto;min-width:150px;"
                    title="{{ lang._('A reservation is a DHCP static mapping. An address set manually on the device itself is not visible to the firewall.') }}">
                <option value="">{{ lang._('All addresses') }}</option>
                <option value="1">{{ lang._('Reserved (DHCP)') }}</option>
                <option value="0">{{ lang._('Dynamic') }}</option>
            </select>

            <button id="btn-refresh" class="btn btn-default" title="{{ lang._('Refresh') }}">
                <i class="fa fa-refresh"></i>
            </button>

            <button id="btn-scan-now" class="btn btn-default" title="{{ lang._('Run scan now') }}">
                <i class="fa fa-search"></i>
            </button>

            <button id="btn-export" class="btn btn-default" title="{{ lang._('Export to CSV') }}">
                <i class="fa fa-download"></i>
            </button>

            <div style="flex-grow:1;"></div>

            <button id="btn-clear" class="btn btn-danger">
                <i class="fa fa-trash"></i> {{ lang._('Clear Database') }}
            </button>
        </div>

        <!-- Tabulka -->
        <table class="table table-condensed table-hover table-striped" id="grid-devices" style="margin-top:0;border-top:2px solid #444;">
            <thead>
                <tr>
                    <th class="sortable" data-col="mac" style="cursor:pointer;white-space:nowrap;">{{ lang._('MAC Address') }} <i class="fa fa-sort"></i></th>
                    <th class="sortable" data-col="ip" style="cursor:pointer;white-space:nowrap;">{{ lang._('IP Address') }} <i class="fa fa-sort"></i></th>
                    <th class="sortable" data-col="hostname" style="cursor:pointer;white-space:nowrap;">{{ lang._('Hostname') }} <i class="fa fa-sort"></i></th>
                    <th class="sortable" data-col="vendor" style="cursor:pointer;white-space:nowrap;">{{ lang._('Vendor') }} <i class="fa fa-sort"></i></th>
                    <th class="sortable" data-col="vlan" style="cursor:pointer;white-space:nowrap;">{{ lang._('Interface') }} <i class="fa fa-sort"></i></th>
                    <th class="sortable" data-col="status" style="cursor:pointer;white-space:nowrap;">{{ lang._('Status') }} <i class="fa fa-sort"></i></th>
                    <th class="sortable" data-col="last_seen" style="cursor:pointer;white-space:nowrap;">{{ lang._('Last Seen') }} <i class="fa fa-sort"></i></th>
                    <th style="white-space:nowrap;">{{ lang._('Actions') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

        <!-- Only shown when a delivery failed and is waiting to be retried. -->
        <div id="queue-panel" style="display:none;margin-top:18px;border-top:2px solid #444;padding-top:12px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                <strong style="font-size:14px;">{{ lang._('Pending notifications') }}</strong>
                <span id="queue-count" class="label label-warning"></span>
                <div style="flex-grow:1;"></div>
                <button id="btn-queue-retry" class="btn btn-xs btn-default">
                    <i class="fa fa-refresh"></i> {{ lang._('Retry now') }}
                </button>
                <button id="btn-queue-discard" class="btn btn-xs btn-danger">
                    <i class="fa fa-trash"></i> {{ lang._('Discard') }}
                </button>
            </div>
            <div id="queue-status" style="font-size:12px;color:#aaa;margin-bottom:6px;"></div>
            <table class="table table-condensed table-striped" id="grid-queue">
                <thead>
                    <tr>
                        <th style="white-space:nowrap;">{{ lang._('Channel') }}</th>
                        <th style="white-space:nowrap;">{{ lang._('Event') }}</th>
                        <th style="white-space:nowrap;">{{ lang._('Devices') }}</th>
                        <th style="white-space:nowrap;">{{ lang._('Queued at') }}</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>


<script>
$(document).ready(function() {

    var translations = {
        deleted:        '{{ lang._('Device deleted') }}',
        delete_error:   '{{ lang._('Error deleting device') }}',
        db_cleared:     '{{ lang._('Database cleared') }}',
        db_clear_error: '{{ lang._('Error clearing database') }}',
        hostname_saved: '{{ lang._('Hostname saved') }}',
        hostname_error: '{{ lang._('Error saving hostname') }}',
        confirm_delete: '{{ lang._('Delete device') }}',
        confirm_clear:  '{{ lang._('Really delete all devices from database?') }}',
        all_vlans:      '{{ lang._('All interfaces') }}',
        click_to_rename: '{{ lang._('Click to rename this device') }}',
        unnamed:        '{{ lang._('unnamed') }}',
        reserved:       '{{ lang._('RESERVED') }}',
        reserved_hint:  '{{ lang._('This MAC has a DHCP reservation') }}',
        no_devices:     '{{ lang._('No devices recorded yet. They appear after the next scan.') }}',
        none_match:     '{{ lang._('No devices match the current filters.') }}',
        queue_retry:    '{{ lang._('Retry now') }}',
        queue_discarded:'{{ lang._('Queued notifications discarded') }}',
        confirm_discard:'{{ lang._('Discard every notification still waiting to be delivered?') }}',
        queue_attempts: '{{ lang._('%s delivery attempt(s) failed.') }}',
        queue_next:     '{{ lang._('Next retry at') }}',
        queue_error:    '{{ lang._('The queue could not be changed') }}'
    };

    var allRows = [], activeVlans = [], activeStatus = '', activeReserved = '', vlanNames = {};
    var sortCol = 'last_seen', sortDir = 'desc';

    // Obnov uložený VLAN filtr
    try { activeVlans = JSON.parse(localStorage.getItem('dm_vlan_filter') || '[]'); } catch(e) {}
    // Anything but an array would throw in the filter and leave the table empty.
    if (!Array.isArray(activeVlans)) activeVlans = [];

    // Toast
    function showToast(msg, type) {
        var bg = type==='success'?'#4CAF50':(type==='error'?'#f44336':'#2196F3');
        var ic = type==='success'?'fa-check-circle':(type==='error'?'fa-exclamation-circle':'fa-info-circle');
        var $t = $('<div>').css({position:'fixed',top:'20px',right:'20px','background-color':bg,color:'white',
            padding:'15px 20px','border-radius':'4px','box-shadow':'0 4px 8px rgba(0,0,0,.3)',
            'z-index':9999,'min-width':'280px',display:'none'})
            .append($('<i>').addClass('fa '+ic), ' ', document.createTextNode(msg));
        $('body').append($t); $t.fadeIn(300);
        setTimeout(function(){ $t.fadeOut(300,function(){ $t.remove(); }); },3000);
    }

    // Verze + statistiky
    $.getJSON('/api/devicemonitor/config/getversion', function(d) {
        $('#plugin-version').text('v'+(d.version||'?'));
    });

    function loadStats() {
        $.ajax({url:'/api/devicemonitor/devices/stats',type:'GET',success:function(d){
            $('#stat-total').text(d.total||0);
            $('#stat-online').text(d.online||0);
        }});
    }

    // Notifications that could not be delivered yet. The panel stays hidden
    // while the queue is empty, which is the normal case.
    function loadQueue() {
        $.ajax({url:'/api/devicemonitor/devices/queue',type:'GET',success:function(d){
            var rows = (d && d.rows) || [];
            var $tbody = $('#grid-queue tbody').empty();
            if (!rows.length) { $('#queue-panel').hide(); return; }
            rows.forEach(function(r){
                // .text() throughout: these values reach the page from the
                // network and must never be rendered as markup.
                $('<tr>').append(
                    $('<td>').text(r.channel||''),
                    $('<td>').text(r.event||''),
                    $('<td>').attr('title', r.macs||'').text(r.devices||0),
                    $('<td>').text(r.created_at||'')
                ).appendTo($tbody);
            });
            $('#queue-count').text(rows.length);

            // One schedule for the whole queue, so it is reported once.
            var state = (d && d.state) || {};
            var $status = $('#queue-status').empty();
            if (state.attempts) {
                var line = translations.queue_attempts.replace('%s', state.attempts);
                if (state.next_attempt) {
                    line += ' ' + translations.queue_next + ' ' + state.next_attempt + '.';
                }
                $status.append(document.createTextNode(line + ' '));
                if (state.last_error) {
                    $status.append($('<span>').css('color','#d9534f').text(state.last_error));
                }
            }
            $('#queue-panel').show();
        }});
    }

    $('#btn-queue-retry').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
        $.ajax({url:'/api/devicemonitor/devices/retryqueue',type:'POST',complete:function(){
            $btn.prop('disabled', false)
                .html('<i class="fa fa-refresh"></i> ' + translations.queue_retry);
            loadQueue();
        }});
    });

    $('#btn-queue-discard').on('click', function() {
        if (!confirm(translations.confirm_discard)) return;
        $.ajax({url:'/api/devicemonitor/devices/discardqueue',type:'POST',success:function(r){
            var ok = r && r.result === 'discarded';
            showToast(ok ? translations.queue_discarded : translations.queue_error, !ok ? 'error' : 'success');
            loadQueue();
        }});
    });

    // VLAN multi-select dropdown
    function buildVlanDropdown(vlans) {
        var $list = $('#vlan-checklist').empty();
        if (!vlans.length) return;
        var allSel = (activeVlans.length === 0);

        $list.append($('<li>').append(
            $('<a>').attr('href','#').css({padding:'5px 14px',display:'flex',alignItems:'center',justifyContent:'space-between'}).append(
                $('<label>').css({margin:0,cursor:'pointer',display:'flex',alignItems:'center',gap:'6px'}).append(
                    $('<input type="checkbox" id="vlan-all">').prop('checked', allSel),
                    $('<span>').css({'font-style':'italic'}).text(translations.all_vlans)
                ),
                $('<a>').attr('href','#').addClass('vlan-select-none')
                    .css({fontSize:'11px',color:'#aaa',marginLeft:'12px',whiteSpace:'nowrap'})
                    .text('Select none')
                    .on('click', function(e){
                        e.preventDefault();
                        e.stopPropagation();
                        // Reset = všechny odškrtnuté + All VLANs zaškrtnuté = žádný filtr
                        $('#vlan-checklist .vlan-cb').prop('checked', false);
                        $('#vlan-all').prop('checked', true);
                        activeVlans = [];
                        try { localStorage.setItem('dm_vlan_filter', JSON.stringify([])); } catch(e2) {}
                        updateVlanLabel();
                        applyFilters();
                    })
            )
        ));

        $list.append($('<li class="divider" style="margin:4px 0;">'));

        vlans.sort().forEach(function(v) {
            var n = vlanNames[v];
            var label = (n && n !== v) ? (v+' \u2013 '+n) : v;
            var chk = allSel || (activeVlans.indexOf(v) !== -1);
            $list.append($('<li>').append(
                $('<a>').attr('href','#').css({padding:'4px 14px',display:'block'}).append(
                    $('<input type="checkbox" class="vlan-cb">').val(v).prop('checked', chk),
                    $('<span>').css('margin-left','8px').text(label)
                )
            ));
        });
        updateVlanLabel();
    }

    // Dropdown nezavírat při kliknutí na checkbox
    $('#vlan-checklist').on('click', function(e){ e.stopPropagation(); });

    $(document).on('change','#vlan-all',function(){
        $('#vlan-checklist .vlan-cb').prop('checked',$(this).prop('checked'));
        persistVlans();
    });
    $(document).on('change','#vlan-checklist .vlan-cb',function(){
        var total=$('#vlan-checklist .vlan-cb').length;
        var checked=$('#vlan-checklist .vlan-cb:checked').length;
        $('#vlan-all').prop('checked', total===checked);
        persistVlans();
    });

    function persistVlans() {
        var sel = [];
        var total = $('#vlan-checklist .vlan-cb').length;
        $('#vlan-checklist .vlan-cb:checked').each(function(){ sel.push($(this).val()); });

        if (sel.length === total || sel.length === 0) {
            // Všechny zaškrtnuté NEBO žádná = žádný filtr
            activeVlans = [];
            $('#vlan-all').prop('checked', true);
        } else {
            activeVlans = sel;
            $('#vlan-all').prop('checked', false);
        }
        try { localStorage.setItem('dm_vlan_filter', JSON.stringify(activeVlans)); } catch(e) {}
        updateVlanLabel();
        applyFilters();
    }

    function updateVlanLabel() {
        if (!activeVlans.length) {
            $('#vlan-filter-label').text(translations.all_vlans);
        } else if (activeVlans.length===1) {
            var n=vlanNames[activeVlans[0]];
            $('#vlan-filter-label').text(activeVlans[0]+(n?' \u2013 '+n:''));
        } else {
            $('#vlan-filter-label').text(activeVlans.length+' VLANs');
        }
    }

    // Filtrování
    function applyFilters() {
        // No early return on an empty set: clearing the database, deleting the
        // last device or filtering everything out all have to reach
        // renderTable(), otherwise the table keeps showing what was there.
        allRows = allRows || [];
        var filtered = allRows.filter(function(r){
            var vo = !activeVlans.length || activeVlans.indexOf(r.vlan) !== -1;
            var so = !activeStatus || r.status === activeStatus;
            var ro = activeReserved === '' || String(r.is_reserved || 0) === activeReserved;
            return vo && so && ro;
        });
        // Řazení
        filtered.sort(function(a, b) {
            var va = a[sortCol] || '';
            var vb = b[sortCol] || '';

            // Numerické řazení pro IP adresy
            if (sortCol === 'ip') {
                var ia = va.split('.').map(Number);
                var ib = vb.split('.').map(Number);
                for (var i = 0; i < 4; i++) {
                    if ((ia[i]||0) !== (ib[i]||0)) {
                        var cmp = (ia[i]||0) < (ib[i]||0) ? -1 : 1;
                        return sortDir === 'asc' ? cmp : -cmp;
                    }
                }
                return 0;
            }

            // Numerické řazení pro MAC adresu (hex)
            if (sortCol === 'mac') {
                var ma = va.replace(/:/g,'').toLowerCase();
                var mb = vb.replace(/:/g,'').toLowerCase();
                var cmp = ma < mb ? -1 : ma > mb ? 1 : 0;
                return sortDir === 'asc' ? cmp : -cmp;
            }

            // Textové řazení pro ostatní
            va = va.toString().toLowerCase();
            vb = vb.toString().toLowerCase();
            if (va === vb) return 0;
            var cmp = va < vb ? -1 : 1;
            return sortDir === 'asc' ? cmp : -cmp;
        });

        renderTable(filtered);
        // Aktualizuj ikony šipek
        $('th.sortable .fa').removeClass('fa-sort-asc fa-sort-desc').addClass('fa-sort');
        $('th.sortable[data-col="'+sortCol+'"] .fa')
            .removeClass('fa-sort')
            .addClass(sortDir === 'asc' ? 'fa-sort-asc' : 'fa-sort-desc');
    }

    // Render tabulky
    function renderTable(rows) {
        var $tbody = $('#grid-devices tbody').empty();
        if (!rows.length) {
            $('<tr>').append(
                $('<td colspan="8">').css({'text-align':'center','padding':'24px','color':'#888'})
                    .text(allRows.length ? translations.none_match : translations.no_devices)
            ).appendTo($tbody);
            return;
        }
        rows.forEach(function(row) {
            // Built as DOM nodes on purpose. hostname comes from a DHCP
            // reservation description, a Dnsmasq override or the rename box,
            // none of which are escaped anywhere on the way in, so string
            // concatenation into .html() would execute whatever it contains.
            var statusHtml = row.status==='online'
                ? '<span style="color:#4CAF50;font-weight:bold;white-space:nowrap;"><i class="fa fa-circle"></i> ONLINE</span>'
                : '<span style="color:#666;font-weight:bold;white-space:nowrap;"><i class="fa fa-circle-o"></i> OFFLINE</span>';

            var $hostname = $('<span class="hostname-display">')
                .attr({'data-mac': row.mac||'', 'data-hostname': row.hostname||'',
                       title: translations.click_to_rename})
                .css({cursor:'pointer','border-bottom':'1px dashed #666'});
            if (row.hostname) {
                $hostname.text(row.hostname);
            } else {
                $hostname.append($('<em>').css('color','#555').text(translations.unnamed));
            }
            $hostname.append(' ', $('<i class="fa fa-pencil">').css({opacity:.45,'font-size':'11px'}));

            var $ip = $('<td>');
            if (row.ip) {
                // The scheme is fixed here, so the address cannot introduce one.
                $ip.append($('<a>').attr({href:'http://'+row.ip, target:'_blank'})
                    .css('color','#5bc0de').text(row.ip));
            }
            if (Number(row.is_reserved)) {
                $ip.append(' ', $('<span class="label label-info">')
                    .css('font-size','10px').attr('title', translations.reserved_hint)
                    .text(translations.reserved));
            }

            var vlanLabel = row.vlan||'';
            if (row.vlan && vlanNames[row.vlan]) vlanLabel += ' \u2013 '+vlanNames[row.vlan];

            $('<tr>').append(
                $('<td>').text(row.mac||''),
                $ip,
                $('<td>').append($hostname),
                $('<td>').text(row.vendor||''),
                $('<td>').text(vlanLabel),
                $('<td>').html(statusHtml),
                $('<td>').text(row.last_seen||''),
                $('<td>').append(
                    $('<button class="btn btn-xs btn-warning command-check">')
                        .attr({'data-row-mac': row.mac||'', 'data-row-ip': row.ip||'',
                               title: 'Check online'})
                        .css('margin-right','2px')
                        .append($('<i class="fa fa-plug">')),
                    $('<button class="btn btn-xs btn-danger command-delete">')
                        .attr('data-row-mac', row.mac||'')
                        .append($('<i class="fa fa-trash">'))
                )
            ).appendTo($tbody);
        });
    }

    // Načtení dat. force=true re-renders even while a rename is open,
    // which is what the rename itself needs once it has saved.
    function loadDevices(force) {
        if (!force && $('#grid-devices tbody input').length) {
            return;
        }
        $.ajax({url:'/api/devicemonitor/devices/search',type:'POST',
            data:{rowCount:-1,current:1,searchPhrase:''},
            success:function(data){
                allRows = data.rows||[];
                var vlans={};
                allRows.forEach(function(r){ if(r.vlan) vlans[r.vlan]=1; });
                buildVlanDropdown(Object.keys(vlans));
                applyFilters();
            }
        });
    }

    // Delegated: the table is rebuilt every 30 seconds, and rebinding a
    // handler per row on each render is pure waste.
    $(document).on('click', '.command-delete', function(){
        var mac=$(this).data('row-mac');
        if (!confirm(translations.confirm_delete+' '+mac+'?')) return;
        $.ajax({url:'/api/devicemonitor/devices/delete',type:'POST',data:{mac:mac},
            success:function(r){
                showToast(r.result==='deleted'?translations.deleted:translations.delete_error,
                          r.result==='deleted'?'success':'error');
                loadDevices(true); loadStats();
            }
        });
    });

    $(document).on('click', '.command-check', function() {
        var mac = $(this).data('row-mac');
        var ip  = $(this).data('row-ip');
        var $btn = $(this);

        if (!ip) {
            showToast('No IP address for this device', 'error');
            return;
        }

        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');

        $.ajax({
            url: '/api/devicemonitor/devices/pingdevice',
            type: 'POST',
            data: { mac: mac, ip: ip },
            success: function(r) {
                $btn.prop('disabled', false).html('<i class="fa fa-plug"></i>');
                if (r.result === 'online') {
                    showToast(ip + ' ONLINE', 'success');
                } else if (r.result === 'offline') {
                    showToast(ip + ' OFFLINE', 'error');
                }
                loadDevices(true);
            },
            error: function() {
                $btn.prop('disabled', false).html('<i class="fa fa-plug"></i>');
                showToast('Ping failed', 'error');
            }
        });
    });

    // Inline editace hostname
    $(document).on('click','.hostname-display',function(){
        var $span=$(this);
        if ($span.find('input').length) return;
        var mac=$span.data('mac');
        // The stored value, not the rendered text: an unnamed device shows
        // the placeholder word, which used to be saved as its hostname.
        var cur=$span.attr('data-hostname')||'';
        var $inp=$('<input type="text" class="form-control input-sm">').val(cur).css({width:'150px',display:'inline-block'});
        $span.html($inp);
        $inp.focus().select();
        function save(){
            $.ajax({url:'/api/devicemonitor/devices/updatehostname',type:'POST',
                data:{mac:mac,hostname:$inp.val().trim()},
                success:function(r){
                    showToast(r.result==='saved'?translations.hostname_saved:translations.hostname_error,
                              r.result==='saved'?'success':'error');
                    loadDevices(true);
                }
            });
        }
        $inp.on('keydown',function(e){
            if(e.key==='Enter') save();
            if(e.key==='Escape') loadDevices(true);
        }).on('blur',function(){ setTimeout(save,150); });
    });

    // Řazení sloupců
    $(document).on('click', 'th.sortable', function() {
        var col = $(this).data('col');
        if (sortCol === col) {
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            sortCol = col;
            sortDir = 'asc';
        }
        applyFilters();
    });
    
    // Toolbar
    $('#filter-status').on('change',function(){ activeStatus=$(this).val(); applyFilters(); });
    $('#filter-reserved').on('change',function(){ activeReserved=$(this).val(); applyFilters(); });

    $('#btn-refresh').on('click',function(){ loadDevices(true); loadStats(); });

    $('#btn-scan-now').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
        $.ajax({ url: '/api/devicemonitor/service/scan', type: 'POST',
            success: function() {
                setTimeout(function() {
                    loadDevices(); loadStats();
                    $btn.prop('disabled', false).html('<i class="fa fa-search"></i>');
                }, 3000);
            },
            error: function() {
                $btn.prop('disabled', false).html('<i class="fa fa-search"></i>');
            }
        });
    });

    // Export CSV - respektuje aktuální filtr
    $('#btn-export').on('click', function() {
        // Vezmi aktuálně zobrazená data (po filtrování)
        var filtered = allRows.filter(function(r) {
            var vo = !activeVlans.length || activeVlans.indexOf(r.vlan) !== -1;
            var so = !activeStatus || r.status === activeStatus;
            var ro = activeReserved === '' || String(r.is_reserved || 0) === activeReserved;
            return vo && so && ro;
        });

        if (!filtered.length) {
            showToast('No data to export', 'error');
            return;
        }

        // Hlavičky
        var cols = ['mac', 'ip', 'hostname', 'vendor', 'vlan', 'status', 'last_seen'];
        var headers = ['MAC Address', 'IP Address', 'Hostname', 'Vendor', 'VLAN', 'Status', 'Last Seen'];

        var csv = headers.join(';') + '\n';
        filtered.forEach(function(row) {
            var line = cols.map(function(c) {
                var val = (row[c] || '').toString();
                // VLAN přidej popis
                if (c === 'vlan' && row.vlan && vlanNames[row.vlan]) {
                    val = row.vlan + ' - ' + vlanNames[row.vlan];
                }
                // Escapuj středník a uvozovky
                val = val.replace(/"/g, '""');
                if (val.indexOf(';') !== -1 || val.indexOf('"') !== -1) {
                    val = '"' + val + '"';
                }
                return val;
            }).join(';');
            csv += line + '\n';
        });

        // Přidej BOM pro správné zobrazení v Excelu
        var bom = '\uFEFF';
        var blob = new Blob([bom + csv], { type: 'text/csv;charset=utf-8;' });
        var url  = URL.createObjectURL(blob);

        // Vytvoř název souboru s datem + aktivním filtrem
        var date    = new Date().toISOString().slice(0,10);
        var vlanPart = activeVlans.length === 1 ? '_' + activeVlans[0] : (activeVlans.length > 1 ? '_multi' : '_all');
        var filename = 'device_monitor_' + date + vlanPart + '.csv';

        var $a = $('<a>').attr({href: url, download: filename}).css('display','none');
        $('body').append($a);
        $a[0].click();
        $a.remove();
        URL.revokeObjectURL(url);

        showToast('Exported ' + filtered.length + ' devices', 'success');
    });

    $('#btn-clear').on('click',function(){
        if (!confirm(translations.confirm_clear)) return;
        $.ajax({url:'/api/devicemonitor/devices/clear',type:'POST',
            success:function(r){
                showToast(r.result==='cleared'?translations.db_cleared:translations.db_clear_error,
                          r.result==='cleared'?'success':'error');
                loadDevices(); loadStats();
            }
        });
    });

    // Init – nejprve načti interface popisky, pak zařízení
    $.ajax({url:'/api/devicemonitor/config/getinterfaces',type:'GET',
        success:function(data){ vlanNames=data||{}; loadDevices(); },
        error:function(){ loadDevices(); }
    });
    loadStats();
    loadQueue();
    setInterval(function(){ loadDevices(); loadStats(); loadQueue(); },30000);
});
</script>