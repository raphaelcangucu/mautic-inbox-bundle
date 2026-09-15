(function () {
    'use strict';
    var translations = {}, locale = 'en-US';
    function t(key, parameters) { var text = translations[key] || key; Object.keys(parameters || {}).forEach(function (name) { text = text.split('%'+name+'%').join(String(parameters[name])); }); return text; }


    // Build a restricted Markdown/WhatsApp presentation with DOM nodes only.
    // Message HTML is always literal; links only allow HTTP(S).
    function messageBody(value, whatsapp) {
        var container = document.createElement('div');
        container.className = 'inbox-message-body';
        function inline(parent, text, depth) {
            if (depth > 8) { parent.appendChild(document.createTextNode(text)); return; }
            var tokens = /(`[^`\n]+`|\[[^\]\n]+\]\(https?:\/\/[^\s<>]+?\)|https?:\/\/[^\s<>]+|\*\*[^\n]+?\*\*|__[^\n]+?__|~~[^\n]+?~~|\*[^*\n]+\*|_[^_\n]+_|~[^~\n]+~)/g;
            var last = 0, match;
            while ((match = tokens.exec(text))) {
                parent.appendChild(document.createTextNode(text.slice(last, match.index)));
                var token = match[0], node, content, suffix = '';
                if (token[0] === '`') {
                    node = document.createElement('code'); node.textContent = token.slice(1, -1);
                } else if (/^https?:\/\//.test(token) || token[0] === '[') {
                    var labeled = /^\[([^\]]+)\]\((.+)\)$/.exec(token);
                    var raw = labeled ? labeled[2] : token;
                    if (!labeled) { suffix = (raw.match(/[.,!?;:)]+$/) || [''])[0]; raw = raw.slice(0, raw.length - suffix.length); }
                    try {
                        var url = new URL(raw);
                        if (!/^https?:$/.test(url.protocol)) throw new Error(t("mautic.inbox.ui.unsupported_link_9a5896"));
                        node = document.createElement('a'); node.href = url.href;
                        node.target = '_blank'; node.rel = 'noopener noreferrer'; node.title = raw;
                        if (labeled) inline(node, labeled[1], depth + 1);
                        else { var label = url.hostname + (url.pathname === '/' ? '' : url.pathname); node.textContent = label.length > 64 ? label.slice(0, 61) + '…' : label; }
                    } catch (e) { node = document.createTextNode(token); suffix = ''; }
                } else {
                    var double = /^(\*\*|__|~~)/.test(token), size = double ? 2 : 1;
                    // Preserve underscores inside identifiers rather than treating them as emphasis.
                    if (token[0] === '_' && /[\p{L}\p{N}]/u.test(text[match.index - 1] || '')) {
                        node = document.createTextNode(token);
                    } else {
                        var tag = token[0] === '~' ? 'del' : double ? 'strong' : token[0] === '*' && whatsapp ? 'strong' : 'em';
                        node = document.createElement(tag); content = token.slice(size, -size); inline(node, content, depth + 1);
                    }
                }
                parent.appendChild(node);
                if (suffix) parent.appendChild(document.createTextNode(suffix));
                last = tokens.lastIndex;
            }
            parent.appendChild(document.createTextNode(text.slice(last)));
        }
        var lines = String(value || '').replace(/\r\n?/g, '\n').split('\n'), list = null, paragraph = null;
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i], match;
            if (/^\s*```/.test(line)) {
                list = paragraph = null;
                var codeLines = [], opening = line.replace(/^\s*```/, '');
                // WhatsApp also uses triple backticks around a single line.
                if (opening.endsWith('```')) codeLines.push(opening.slice(0, -3));
                else { while (++i < lines.length && !/^\s*```\s*$/.test(lines[i])) codeLines.push(lines[i]); }
                var pre = document.createElement('pre'), code = document.createElement('code');
                code.textContent = codeLines.join('\n'); pre.appendChild(code); container.appendChild(pre); continue;
            }
            if (!line.trim()) { list = paragraph = null; continue; }
            if ((match = /^\s*(?:([-*])\s+|(\d+)\.\s+)(.+)$/.exec(line))) {
                var type = match[2] ? 'ol' : 'ul';
                if (!list || list.tagName.toLowerCase() !== type) { list = document.createElement(type); if (match[2]) list.start = Number(match[2]); container.appendChild(list); }
                paragraph = null; var li = document.createElement('li'); inline(li, match[3], 0); list.appendChild(li); continue;
            }
            list = null;
            if ((match = /^(#{1,6})\s+(.+)$/.exec(line))) {
                paragraph = null; var heading = document.createElement('p'); heading.className = 'inbox-message-heading'; inline(heading, match[2], 0); container.appendChild(heading); continue;
            }
            if ((match = /^>\s?(.*)$/.exec(line))) {
                paragraph = null; var quote = document.createElement('blockquote'); inline(quote, match[1], 0); container.appendChild(quote); continue;
            }
            if (!paragraph) { paragraph = document.createElement('p'); container.appendChild(paragraph); }
            else paragraph.appendChild(document.createElement('br'));
            inline(paragraph, line, 0);
        }
        return container;
    }

    function createInboxAlerts(root) {
        var button = root.querySelector('#inbox-sound'), pending = new Map(), audio = null, disposed = false;
        var key = 'mautic-inbox-sound-' + root.dataset.currentUser;
        var enabled = true;
        try { enabled = localStorage.getItem(key) !== 'off'; } catch (e) {}
        var icons = Array.from(document.querySelectorAll('link[rel~="icon"]')).map(function(el){return {el:el, href:el.getAttribute('href'), type:el.getAttribute('type')};});
        var badge = document.createElement('link'); badge.rel = 'icon'; badge.type = 'image/png';
        var base = new Image();
        if (icons.length) base.src = icons[0].el.href;
        base.onload = paint;
        function paint() {
            if (disposed) return;
            if (!pending.size) {
                badge.remove();
                icons.forEach(function(i){if(i.href===null)i.el.removeAttribute('href');else i.el.setAttribute('href',i.href);if(i.type===null)i.el.removeAttribute('type');else i.el.setAttribute('type',i.type);});
                return;
            }
            var canvas = document.createElement('canvas'); canvas.width = canvas.height = 32;
            var ctx = canvas.getContext('2d'); if (!ctx) return;
            ctx.fillStyle = '#535ca0'; ctx.fillRect(0,0,32,32);
            try { if(base.complete && base.naturalWidth)ctx.drawImage(base,0,0,32,32); } catch(e) {}
            ctx.fillStyle = '#dc3545'; ctx.beginPath(); ctx.arc(23,9,9,0,Math.PI*2);ctx.fill();
            ctx.fillStyle='#fff';ctx.font='bold 12px sans-serif';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText(pending.size>9?'9+':String(pending.size),23,10);
            try { badge.href=canvas.toDataURL();icons.forEach(function(i){i.el.href=badge.href;i.el.type='image/png';});document.head.appendChild(badge); } catch(e) {}
        }
        function label() {
            button.textContent = enabled ? (audio && audio.state==='running' ? t("mautic.inbox.ui.sound_on_ff7013") : t("mautic.inbox.ui.enable_sound_e0b13b")) : t("mautic.inbox.ui.sound_off_95de2d");
            button.setAttribute('aria-pressed',String(enabled && !!audio && audio.state==='running'));
            button.title = enabled ? t("mautic.inbox.ui.sound_notifications_for_new_messages_click_to_enable_or_mute_5f2dc2") : t("mautic.inbox.ui.enable_sound_notifications_9d9271");
        }
        function unlock() {
            if(!enabled || disposed) return;
            var Audio = window.AudioContext || window.webkitAudioContext;
            if(!Audio) {button.textContent=t("mautic.inbox.ui.sound_unavailable_029b22");return;}
            try {if(!audio)audio=new Audio();audio.resume().then(label).catch(label);} catch(e) {label();}
        }
        function sound() {
            if(!enabled || !audio || audio.state!=='running' || disposed)return;
            [660,880].forEach(function(f,i){var osc=audio.createOscillator(),gain=audio.createGain(),start=audio.currentTime+i*0.13;osc.frequency.value=f;gain.gain.setValueAtTime(0,start);gain.gain.linearRampToValueAtTime(0.08,start+0.015);gain.gain.exponentialRampToValueAtTime(0.001,start+0.15);osc.connect(gain);gain.connect(audio.destination);osc.start(start);osc.stop(start+0.16);});
        }
        button.addEventListener('click',function(){
            if(enabled && audio && audio.state==='running')enabled=false;else enabled=true;
            try{localStorage.setItem(key,enabled?'on':'off');}catch(e){}
            if(enabled)unlock();label();
        });
        function interaction(event){if(event.target!==button)unlock();}
        root.addEventListener('pointerdown',interaction);root.addEventListener('keydown',interaction);
        function preference(event){if(event.key===key){enabled=event.newValue!=='off';label();}}
        window.addEventListener('storage',preference);
        label();
        return {
            receive:function(items){
                var fresh=items.filter(function(item){return !pending.has(String(item.id));});
                fresh.forEach(function(item){pending.set(String(item.id),Number(item.state_id));});
                if(!fresh.length)return;paint();
                if(!enabled || !audio || audio.state!=='running')return;
                var last=Math.max.apply(null,fresh.map(function(i){return Number(i.id);}));
                var emit=function(){if(disposed)return;var marker=key+'-last';try{if(Number(localStorage.getItem(marker)||0)>=last)return;localStorage.setItem(marker,String(last));}catch(e){}sound();};
                if(navigator.locks)navigator.locks.request(key,emit).catch(function(){});else emit();
            },
            acknowledge:function(stateId){pending.forEach(function(id,message){if(id===Number(stateId))pending.delete(message);});paint();},
            dispose:function(){pending.clear();paint();disposed=true;root.removeEventListener('pointerdown',interaction);root.removeEventListener('keydown',interaction);window.removeEventListener('storage',preference);base.onload=null;if(audio)audio.close().catch(function(){});}
        };
    }

    function boot() {
        var root = document.getElementById('inbox-app');
        if (!root || root.dataset.ready === '1') return;
        root.dataset.ready = '1';
        try { translations = JSON.parse(root.dataset.translations || '{}'); } catch (e) { translations = {}; }
        locale = (root.dataset.locale || 'en_US').replace(/_/g, '-');
        var alerts = createInboxAlerts(root), notificationCursor = null;

        var state = {queue: 'all', view:'inbox', selected: null, rows: [], next: null, older: null, mode: 'reply', since: new Date().toISOString(), draftTimer: null, pollTimer: null};
        var templateItems = [], templateOwner = null, templateRevision = 0, templateSending = false, templateAttempt = null, templateBlocked = null;
        var cannedSettings = [];
        try { cannedSettings = JSON.parse(root.dataset.cannedSettings || '[]'); } catch (e) { cannedSettings = []; }
        var draftCache = {}, draftTimers = {}, draftWrites = {}, sendAttempts = {}, retryAttempts = {}, selectionRevision = 0, listRevision = 0, sending = false;
        var $ = function (id) { return root.querySelector('#' + id); };
        var endpoint = function (name, id) { var url = root.dataset[name]; return id ? url.replace(/\/0(?=\/|$)/, '/' + id) : url; };
        var channelLabel = function(channel){return {whatsapp:'WhatsApp',instagram:'Instagram',facebook:'Facebook'}[channel] || channel;};
        var iconPaths = {
            facebook:'M14 21v-9h3l1-4h-4V6c0-1 1-2 2-2h2V1h-3c-4 0-5 3-5 5v2H7v4h3v9',inbox:'M4 4h16v16H4z M4 14h5l2 3h2l2-3h5',chat:'M20 11a8 8 0 0 1-8 8H6l-4 3V11a9 9 0 0 1 18 0z',comment:'M4 4h16v12H9l-5 4z M8 8h8 M8 12h5',bolt:'M13 2L5 14h6l-1 8 9-13h-6z',search:'M21 21l-5-5 M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0',filter:'M4 6h16 M7 12h10 M10 18h4',panel:'M3 4h18v16H3z M15 4v16',back:'M14 5l-7 7 7 7',reply:'M9 5l-6 6 6 6 M3 11h11a7 7 0 0 1 7 7',note:'M5 3h14v14l-4 4H5z M9 8h6 M9 12h6',lock:'M6 10h12v11H6z M8 10V6a4 4 0 0 1 8 0v4',shield:'M12 2l8 4v6c0 5-8 10-8 10S4 17 4 12V6z M8 12l3 3 5-6',instagram:'M6 3h12a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3z M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M17.5 6.5h.01',whatsapp:'M21 11a9 9 0 0 1-13 8l-5 2 1-5a9 9 0 1 1 17-5 M8 7c0 5 4 9 9 9'
        };
        iconPaths.settings = 'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21h-4v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3v-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.5V3h4v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.5 1h.1v4h-.1a1.7 1.7 0 0 0-1.5 1z';
        iconPaths.sound = 'M11 5 6 9H3v6h3l5 4z M15 9a4 4 0 0 1 0 6 M18 6a8 8 0 0 1 0 12';
        iconPaths.agent = 'M12 13a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M4 21a8 8 0 0 1 16 0 M18 5h4 M20 3v4';
        function icon(name) { var svg=document.createElementNS('http://www.w3.org/2000/svg','svg'); svg.setAttribute('viewBox','0 0 24 24'); svg.setAttribute('aria-hidden','true'); var path=document.createElementNS('http://www.w3.org/2000/svg','path'); path.setAttribute('d',iconPaths[name] || iconPaths.chat); svg.appendChild(path); return svg; }
        root.querySelectorAll('[data-icon]').forEach(function(el){el.appendChild(icon(el.dataset.icon));});
        function avatar(el, name, id, channel, photo) { el.textContent=(name || '?').split(/\s+/).slice(0,2).map(function(w){return w.charAt(0);}).join('').toUpperCase(); el.dataset.tone=String(Number(id || 0)%5); if(photo){try{var photoUrl=new URL(photo,location.origin);if(photoUrl.protocol==='https:' || photoUrl.origin===location.origin){var img=document.createElement('img');img.src=photoUrl.href;img.alt=t("mautic.inbox.ui.photo_of_5bece3") + name;img.loading='lazy';img.referrerPolicy='no-referrer';img.onerror=function(){img.remove();};el.appendChild(img);}}catch(e){}} if(channel){var badge=document.createElement('span');badge.className='inbox-channel-dot '+channel;badge.appendChild(icon(channel));el.appendChild(badge);} }
        function shortTime(iso) {var d=new Date(iso), now=new Date();return d.toDateString()===now.toDateString()?new Intl.DateTimeFormat(locale,{hour:'2-digit',minute:'2-digit'}).format(d):new Intl.DateTimeFormat(locale,{day:'2-digit',month:'short'}).format(d);}
        var escapeTime = function (iso) { return new Intl.DateTimeFormat(locale, {day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit'}).format(new Date(iso)); };

        async function api(url, options) {
            options = options || {};
            options.headers = Object.assign({'Accept': 'application/json'}, options.headers || {});
            if (options.body) {
                options.headers['Content-Type'] = 'application/json';
                options.headers['X-CSRF-Token'] = root.dataset.csrf;
            }
            var response = await fetch(url, options);
            var data;
            try { data = await response.json(); } catch (e) { data = {}; }
            if (!response.ok) { var requestError = new Error(data.error || t("mautic.inbox.ui.could_not_complete_the_action_0cb5b3")); requestError.status = response.status; throw requestError; }
            return data;
        }

        function query(cursor) {
            var q = new URLSearchParams({queue: state.queue, kind:state.view === 'comments' ? 'comments' : 'private', lifecycle: $('inbox-lifecycle').value, channel: $('inbox-channel').value, search: $('inbox-search').value.trim(), needs_response: $('inbox-needs-response').checked ? '1' : '0', limit: '25'});
            if (cursor) q.set('cursor', cursor);
            return q.toString();
        }

        async function loadList(append, silent) {
            var revision = ++listRevision;
            if (!silent) { $('inbox-list-status').className = 'inbox-state'; $('inbox-list-status').textContent = append ? t("mautic.inbox.ui.loading_more_cfca50") : t("mautic.inbox.ui.loading_conversations_cb1a50"); }
            try {
                var data = await api(root.dataset.listUrl + '?' + query(append ? state.next : null));
                if (revision !== listRevision) return;
                var sameRows = !append && JSON.stringify(state.rows) === JSON.stringify(data.items);
                state.rows = append ? state.rows.concat(data.items) : data.items;
                state.next = data.next_cursor;
                Object.keys(data.counts).forEach(function (key) { var node = root.querySelector('[data-count="' + key + '"]'); if (node) node.textContent = data.counts[key]; });
                if (!sameRows) renderList();
                else updateListStatus();
                if ($('inbox-list-total')) $('inbox-list-total').textContent = data.counts[state.queue] || data.items.length;
            } catch (error) {
                if (revision !== listRevision) return;
                $('inbox-list-status').className = 'inbox-error';
                $('inbox-list-status').textContent = error.message;
            }
        }

        function renderList() {
            var list = $('inbox-list');
            list.textContent = '';
            state.rows.forEach(function (item) {
                var button = document.createElement('button');
                button.className = 'inbox-list-item' + (state.selected && state.selected.id === item.id ? ' active' : '');
                button.type = 'button';
                button.addEventListener('click', function () { select(item.id); });
                var av=document.createElement('div');av.className='inbox-avatar';avatar(av,item.contact_name,item.id,item.channel,item.avatar_url);
                var copy=document.createElement('div');copy.className='inbox-list-copy';
                var title = document.createElement('div'); title.className = 'inbox-list-title';
                var name = document.createElement('span'); name.textContent = item.contact_name;
                var time = document.createElement('span'); time.className = 'inbox-list-time'; time.textContent = shortTime(item.last_message_at);
                title.append(name, time);
                var meta = document.createElement('div'); meta.className = 'inbox-list-meta'; meta.textContent = channelLabel(item.channel) + ' · ' + (item.asset.handle ? '@' + item.asset.handle : (item.asset.phone || item.asset.name));
                var flags = document.createElement('div'); flags.className = 'inbox-list-flags';
                if (item.needs_response) flags.appendChild(pill(t("mautic.inbox.ui.awaiting_reply_f6a6ba"), 'response'));
                if (item.unread) flags.appendChild(pill(String(item.unread), 'unread'));
                if (item.lifecycle === 'snoozed') flags.appendChild(pill(t("mautic.inbox.ui.snoozed_435da9")));
                var preview=document.createElement('div');preview.className='inbox-list-preview';preview.textContent=item.preview || item.last_message_preview || item.asset.name;
                copy.append(title,meta,preview,flags);button.append(av,copy);list.appendChild(button);
            });
            updateListStatus();
        }

        function updateListStatus() {
            $('inbox-list-status').className = state.rows.length ? 'hide' : 'inbox-state';
            $('inbox-list-status').textContent = t("mautic.inbox.ui.no_conversations_found_12522a");
            $('inbox-more').classList.toggle('hide', !state.next);
        }

        function pill(text, extra) { var el = document.createElement('span'); el.className = 'inbox-pill ' + (extra || ''); el.textContent = text; return el; }

        async function select(id) {
            alerts.acknowledge(id);
            var revision = ++selectionRevision;
            try {
                var detail = await api(endpoint('detailUrl', id));
                if (revision !== selectionRevision) return;
                detail.drafts = Object.assign({}, detail.drafts, draftCache[id] || {});
                state.selected = detail; root.classList.add('has-selection');
                state.older = null;
                renderList(); renderDetail();
                var timeline = await api(endpoint('timelineUrl', id) + '?limit=100');
                if (revision !== selectionRevision) return;
                state.older = timeline.next_cursor;
                renderTimeline(timeline.items, false);
                if (window.matchMedia('(max-width:760px)').matches) {
                    window.scrollTo(0, 0);
                    var wrapper = document.getElementById('app-wrapper');
                    if (wrapper) wrapper.scrollTop = 0;
                }
                api(endpoint('stateUrl', id), {method:'POST', body:JSON.stringify({action:'read'})}).catch(function () {});
            } catch (error) { showThreadError(error.message); }
        }

        var aiSelectedId=null, aiRequest=0;
        function renderAiAssignment(assignment) {
            var card=$('inbox-ai-agent-card'), header=$('inbox-ai-header'), assignee=$('inbox-assignee-label');
            if(!assignment){card.classList.add('hide');header.classList.add('hide');card.classList.remove('processing','paused');header.classList.remove('processing','paused');assignee.classList.remove('is-ai');return;}
            var processing=!!assignment.processing, paused=assignment.status==='paused';
            card.classList.remove('hide');header.classList.remove('hide');
            card.classList.toggle('processing',processing);header.classList.toggle('processing',processing);
            card.classList.toggle('paused',paused);header.classList.toggle('paused',paused);
            $('inbox-ai-agent-name').textContent=assignment.name;
            $('inbox-ai-agent-activity').textContent=assignment.status_label;
            $('inbox-ai-agent-progress').textContent='∞';
            $('inbox-ai-agent-progress').title=assignment.count+' · '+t('mautic.inbox.ai.unlimited');
            $('inbox-ai-header-name').textContent=assignment.name;
            $('inbox-ai-header-status').textContent=assignment.status_label;
            if(['active','queued','finishing'].includes(assignment.status)){assignee.textContent=assignment.name+' · AI';assignee.classList.add('is-ai');}else assignee.classList.remove('is-ai');
        }
        async function renderAi(forceOptions) {
            if(!state.selected)return;var id=state.selected.id, request=++aiRequest, select=$('inbox-ai-select'), changed=aiSelectedId!==id;
            if(changed){select.disabled=true;$('inbox-ai-status').textContent='';renderAiAssignment(null);}
            try{var info=await api(endpoint('aiUrl',id));if(request!==aiRequest||!state.selected||state.selected.id!==id)return;
                if(changed||forceOptions){select.textContent='';var empty=document.createElement('option');empty.value='';empty.textContent=t('mautic.inbox.ai.choose');select.appendChild(empty);info.agents.forEach(function(agent){var option=document.createElement('option');option.value=agent.key;option.textContent=agent.name+(agent.reason_label?' — '+agent.reason_label:'');option.title=agent.reason_label||'';option.disabled=!agent.allowed;select.appendChild(option);});}
                aiSelectedId=id;var allowed=info.agents.filter(function(agent){return agent.allowed;});select.disabled=!info.can_assign||!allowed.length;
                var assignment=info.assignment;renderAiAssignment(assignment);
                $('inbox-ai-status').textContent=assignment?(assignment.reason_label||''):(!info.can_assign?t('mautic.inbox.ai.read_only'):(!allowed.length?t('mautic.inbox.ai.none_available'):''));
                var validationError=root.querySelector('#inbox-feedback .inbox-error');
                if(assignment&&validationError&&validationError.textContent.trim()===t('mautic.inbox.ai.validate_first').trim())validationError.remove();
            }catch(e){if(request===aiRequest)$('inbox-ai-status').textContent=e.message;}
        }
        $('inbox-ai-select').addEventListener('change',async function(){if(!this.value||!state.selected)return;var id=state.selected.id;try{await api(endpoint('aiUrl',id),{method:'POST',body:JSON.stringify({agent:this.value,version:state.selected.version})});$('inbox-feedback').textContent='';aiSelectedId=null;await select(id);}catch(e){showThreadError(e.message,'ai');}this.value='';});
        $('inbox-ai-reset').addEventListener('click',async function(){if(!state.selected)return;var button=this,id=state.selected.id;button.disabled=true;try{await api(endpoint('aiUrl',id),{method:'POST',body:JSON.stringify({action:'reset',version:state.selected.version})});aiSelectedId=null;await select(id);showThreadFeedback(t('mautic.inbox.ai.reset_success'));}catch(e){showThreadError(e.message,'ai');}finally{button.disabled=false;}});
        function renderDetail() {
            var item = state.selected;
            renderAi();
            if(templateOwner !== item.id) { ++templateRevision; templateOwner=item.id; templateItems=[]; templateAttempt=null; $('inbox-template-panel').classList.add('hide');root.querySelector('.inbox-composer').classList.remove('template-mode'); }
            $('inbox-empty').classList.add('hide'); $('inbox-selected').classList.remove('hide');
            $('inbox-contact-name').textContent = item.contact_name;
            avatar($('inbox-header-avatar'),item.contact_name,item.id,null,item.avatar_url);
            avatar($('inbox-profile-avatar'),item.contact_name,item.id,null,item.avatar_url);
            $('inbox-profile-name').textContent=item.contact_name;
            $('inbox-profile-handle').textContent=item.contact_handle || (item.channel==='whatsapp' ? item.recipient : t("mautic.inbox.ui.network_id_f461ad")+item.recipient);
            $('inbox-lifecycle-badge').textContent={open:t("mautic.inbox.ui.open_5c072d"),snoozed:t("mautic.inbox.ui.snoozed_435da9"),resolved:t("mautic.inbox.ui.resolved_0d46ce")}[item.lifecycle] || item.lifecycle;
            $('inbox-assignee-label').textContent=item.assignee ? item.assignee.name : t("mautic.inbox.ui.unassigned_5c8896");
            $('inbox-composer-channel').textContent=channelLabel(item.channel);
            $('inbox-context').title = item.asset.name + ' · ' + item.recipient;
            $('inbox-context').textContent = (item.conversation_kind || '') + ' · ' + channelLabel(item.channel) + ' · ' + (item.asset.handle ? '@' + item.asset.handle : (item.asset.phone || item.asset.name)) + (item.assignee ? ' · ' + item.assignee.name : t("mautic.inbox.ui.unassigned_3986c8"));
            $('inbox-take').classList.toggle('hide', !!item.assignee);
            $('inbox-transfer').classList.remove('hide');
            $('inbox-resolve').classList.toggle('hide', item.lifecycle === 'resolved');
            $('inbox-reopen').classList.toggle('hide', item.lifecycle !== 'resolved');
            $('inbox-send').disabled = item.lifecycle === 'resolved' || (state.mode === 'reply' && !item.can_reply);
            $('inbox-contact-card').textContent = '';
            if (item.contact) {
                var link = document.createElement('a'); link.href = item.contact.url; link.textContent = item.contact.name === t("mautic.inbox.ui.unnamed_contact_666b99") ? t("mautic.inbox.ui.view_contact_in_mautic_199894") : item.contact.name; link.className = 'text-primary';
                $('inbox-contact-card').appendChild(link);
                [item.contact.email, item.contact.phone].filter(Boolean).forEach(function (value) { var div = document.createElement('div'); div.textContent = value; $('inbox-contact-card').appendChild(div); });
            } else $('inbox-contact-card').textContent = t("mautic.inbox.ui.contact_not_linked_yet_e9a529");
            $('inbox-channel-card').textContent = item.asset.name + (item.asset.handle ? ' · @' + item.asset.handle : (item.asset.phone ? ' · ' + item.asset.phone : ''));
            $('inbox-takeover-text').textContent = item.human_takeover ? t("mautic.inbox.ui.automation_paused_for_this_conversation_5e3144") : t("mautic.inbox.ui.automation_available_a68946");
            var originCard=$('inbox-origin-card');originCard.textContent='';
            $('inbox-origin-section').classList.toggle('hide',!(item.origins && item.origins.length));
            (item.origins || []).forEach(function(origin){if(origin.image){var picture=document.createElement('img');picture.src=origin.image;picture.alt=t("mautic.inbox.ui.source_post_eed81e");picture.className='inbox-origin-image';picture.loading='lazy';picture.referrerPolicy='no-referrer';picture.onerror=function(){picture.remove();};originCard.appendChild(picture);}var title=document.createElement('strong');title.textContent=origin.title || t("mautic.inbox.ui.source_post_eed81e");originCard.appendChild(title);var author=document.createElement('p');author.textContent=t("mautic.inbox.ui.comment_by_8b43f4") + (origin.author || item.contact_name);originCard.appendChild(author);var quote=document.createElement('blockquote');quote.textContent=origin.body;originCard.appendChild(quote);if(origin.permalink){try{var url=new URL(origin.permalink);if(url.protocol==='https:'&&['instagram.com','www.instagram.com','facebook.com','www.facebook.com'].includes(url.hostname)){var link=document.createElement('a');link.href=url.href;link.textContent=t("mautic.inbox.ui.view_post_on_b9e2d7")+channelLabel(item.channel);link.target='_blank';link.rel='noopener noreferrer';originCard.appendChild(link);}}catch(e){}}if(origin.related_state_id){var button=document.createElement('button');button.className='btn btn-default';button.textContent=origin.related_kind==='comments'?t("mautic.inbox.ui.view_original_comment_0d3156"):t("mautic.inbox.ui.open_private_conversation_edf027");button.addEventListener('click',function(){state.view=origin.related_kind==='comments'?'comments':'inbox';root.querySelectorAll('.inbox-tabs button').forEach(function(tab){tab.classList.toggle('active',tab.dataset.view===state.view);});$('inbox-list-heading').textContent=state.view==='comments'?t("mautic.inbox.ui.comments_6fe305"):t("mautic.inbox.ui.conversations_86d0e6");loadList(false);select(origin.related_state_id);});originCard.appendChild(button);}});
            $('inbox-composer-text').value = item.drafts[state.mode] || '';
            updateComposer();
        }

        var timelineFingerprint = null, timelineOwner = null;
        function renderTimeline(items, prepend) {
            var box = $('inbox-timeline'), scroller=box.parentElement;
            var fingerprint=JSON.stringify(items), selectedId=state.selected && state.selected.id;
            if(!prepend && timelineOwner===selectedId && timelineFingerprint===fingerprint) return;
            var oldTop=scroller.scrollTop, oldHeight=scroller.scrollHeight, atBottom=oldHeight-oldTop-scroller.clientHeight<80, changed=timelineOwner!==selectedId;
            timelineOwner=selectedId;timelineFingerprint=prepend?null:fingerprint;
            if (!prepend) box.textContent = '';
            var day = null;
            var fragment = document.createDocumentFragment();
            items.forEach(function (item) { var nextDay=new Intl.DateTimeFormat(locale,{day:'numeric',month:'long'}).format(new Date(item.timestamp)); if(nextDay!==day){var sep=document.createElement('div');sep.className='inbox-date-separator';sep.textContent=nextDay;fragment.appendChild(sep);day=nextDay;} fragment.appendChild(timelineNode(item)); });
            if (prepend) box.insertBefore(fragment, box.firstChild); else box.appendChild(fragment);
            $('inbox-older').classList.toggle('hide', !state.older);
            if(prepend) scroller.scrollTop=oldTop+scroller.scrollHeight-oldHeight;
            else scroller.scrollTop=changed || atBottom?scroller.scrollHeight:oldTop;
        }

        function timelineNode(item) {
            var node = document.createElement('article');
            node.className = 'inbox-message ' + item.kind + (item.direction === 'outbound' || item.kind === 'outbound' ? ' outbound' : '') + (item.ai ? ' ai-authored' : '');
            if (item.kind === 'event') {
                node.textContent = eventLabel(item) + ' · ' + escapeTime(item.timestamp); return node;
            }
            if(item.ai){var aiBadge=document.createElement('div');aiBadge.className='inbox-ai-message-badge';aiBadge.textContent='✦ '+(item.ai.agent||'AI')+' · AI';node.appendChild(aiBadge);}
            var body = messageBody(item.body, state.selected && state.selected.channel === 'whatsapp' && item.kind !== 'note');
            var meta = document.createElement('small');
            var label = item.kind === 'note' ? t("mautic.inbox.ui.internal_note_010aa1") : item.kind === 'comment' ? t("mautic.inbox.ui.public_comment_4a1398") : item.kind === 'outbound' || item.direction === 'outbound' ? outboundStatus(item.status) : t("mautic.inbox.ui.message_received_0d110d");
            var author=item.ai?(item.ai.agent||'AI')+' · AI':item.author;
            meta.textContent = label + (author ? ' · ' + author : '') + ' · ' + escapeTime(item.timestamp);
            if (item.status === 'failed') meta.className = 'inbox-status-failed';
            if(item.content_label){var contentLabel=document.createElement('div');contentLabel.className='inbox-content-label';contentLabel.textContent=item.content_label;node.appendChild(contentLabel);}
            node.appendChild(body);
            (item.attachments || []).forEach(function(a){var card=document.createElement('div');card.className='inbox-attachment';var safe=null;try{var u=new URL(a.url);if(u.protocol==='https:')safe=u.href;}catch(e){}if(safe){
                if(['image','sticker','video','audio'].includes(a.type)){
                    var media=document.createElement(a.type==='image'||a.type==='sticker'?'img':a.type);
                    media.className='inbox-message-media';
                    if(media.tagName==='IMG'){media.alt=a.label;media.loading='lazy';media.referrerPolicy='no-referrer';}
                    else {media.controls=true;media.preload='metadata';}
                    if(a.type==='video'){media.playsInline=true;media.setAttribute('aria-label',t("mautic.inbox.ui.video_preview_b48d9b"));}
                    media.src=a.type==='video' && !new URL(safe).hash ? safe+'#t=0.001' : safe;
                    media.onerror=function(){
                        if(media.dataset.autoRetry!=='1'){
                            media.dataset.autoRetry='1';
                            window.setTimeout(function(){var retryUrl=new URL(safe);retryUrl.searchParams.set('_media_retry',String(Date.now()));media.src=a.type==='video'?retryUrl.href+'#t=0.001':retryUrl.href;},650);
                            return;
                        }
                        media.hidden=true;
                        if(card.querySelector('.inbox-media-retry'))return;
                        var unavailable=document.createElement('span');unavailable.className='inbox-media-unavailable';unavailable.textContent=t("mautic.inbox.ui.could_not_load_the_preview_9f03de");
                        var reload=document.createElement('button');reload.type='button';reload.className='btn btn-link btn-sm inbox-media-retry';reload.textContent=t('mautic.inbox.ui.media_retry');reload.onclick=function(){unavailable.remove();reload.remove();media.hidden=false;media.dataset.autoRetry='0';var retryUrl=new URL(safe);retryUrl.searchParams.set('_media_retry',String(Date.now()));media.src=a.type==='video'?retryUrl.href+'#t=0.001':retryUrl.href;};
                        card.prepend(reload);card.prepend(unavailable);
                    };
                    if(media.tagName==='IMG'){
                        var previewLink=document.createElement('a');previewLink.href=safe;previewLink.target='_blank';previewLink.rel='noopener noreferrer';previewLink.setAttribute('aria-label',t("mautic.inbox.ui.open_full_size_image_44fa9f"));previewLink.appendChild(media);card.appendChild(previewLink);
                    } else card.appendChild(media);
                }
                var link=document.createElement('a');link.href=safe;link.target='_blank';link.rel='noopener noreferrer';link.textContent=t("mautic.inbox.ui.open_825b3a") + a.label;card.appendChild(link);}else card.textContent=a.label + t("mautic.inbox.ui.the_file_was_not_stored_request_it_again_if_needed_1cc4cb");node.appendChild(card);});
            if(item.kind==='automatic'&&!item.ai)meta.textContent=t("mautic.inbox.ui.channel_message_f3ffdf")+meta.textContent;
            node.appendChild(meta);
            if (item.kind === 'comment' && item.context) { var context = document.createElement('div'); context.className = 'inbox-list-meta'; context.textContent = t("mautic.inbox.ui.post_linked_to_the_original_comment_405f5e"); node.appendChild(context); }
            if (item.failure) { var failure = document.createElement('div'); failure.className = 'inbox-status-failed'; failure.textContent = item.failure; node.appendChild(failure); }
            if (item.retryable) {
                var retry = document.createElement('button'), attempt = retryAttempts[item.id];
                retry.type = 'button'; retry.className = 'btn btn-default btn-sm inbox-retry';
                retry.textContent = attempt && attempt.busy ? t('mautic.inbox.ui.sending_again') : t('mautic.inbox.ui.send_again');
                retry.disabled = !!(attempt && attempt.busy) || !state.selected || state.selected.lifecycle === 'resolved' || (!state.selected.can_reply && !state.selected.can_take_and_reply);
                retry.addEventListener('click', function(){ retryOutbound(item, retry); });
                node.appendChild(retry);
            }
            return node;
        }

        function outboundStatus(status) { return {pending:t("mautic.inbox.ui.queued_a3ecf1"),queued:t("mautic.inbox.ui.queued_a3ecf1"),processing:t("mautic.inbox.ui.sending_569978"),waiting:t("mautic.inbox.ui.waiting_to_retry_5ea365"),accepted:t("mautic.inbox.ui.accepted_by_meta_212288"),sent:t("mautic.inbox.ui.sent_e1be83"),delivered:t("mautic.inbox.ui.delivered_e0ca94"),read:t("mautic.inbox.ui.read_5ac9d4"),uncertain:t("mautic.inbox.ui.unconfirmed_send_3c9317"),failed:t("mautic.inbox.ui.not_sent_3f0e80")}[status] || status || t("mautic.inbox.ui.unconfirmed_90d72f"); }
        function eventLabel(item) { return {created:t("mautic.inbox.ui.conversation_created_fa0fd7"),reopened:t("mautic.inbox.ui.conversation_reopened_by_a_new_message_306e4e"),taken:t("mautic.inbox.ui.conversation_assigned_e9e32d"),transfer:t("mautic.inbox.ui.conversation_transferred_f5ab14"),resolve:t("mautic.inbox.ui.conversation_resolved_973a89"),reopen:t("mautic.inbox.ui.conversation_reopened_5108d0"),snooze:t("mautic.inbox.ui.conversation_snoozed_2489e7"),unassign:t("mautic.inbox.ui.conversation_returned_to_the_queue_dee084"),woken:t("mautic.inbox.ui.snooze_period_ended_360c38"),reply_queued:t("mautic.inbox.ui.reply_queued_8a4e0a"),reply_requested:t("mautic.inbox.ui.reply_requested_immediate"),reply_retried:t("mautic.inbox.ui.sent_again")}[item.event] || t("mautic.inbox.ui.conversation_updated_f74900"); }

        async function retryOutbound(item, button) {
            if (!state.selected || !item.retryable || (retryAttempts[item.id] && retryAttempts[item.id].busy)) return;
            var selectedId = state.selected.id;
            var attempt = retryAttempts[item.id] || {id:(window.crypto && crypto.randomUUID ? crypto.randomUUID().replace(/-/g, '') : String(Date.now()) + Math.random().toString(36).slice(2)).slice(0, 64)};
            retryAttempts[item.id] = attempt; attempt.busy = true; button.disabled = true; button.textContent = t('mautic.inbox.ui.sending_again');
            try {
                $('inbox-feedback').textContent = '';
                if (state.selected && state.selected.id === selectedId && state.selected.can_take_and_reply) {
                    var taken = await api(endpoint('takeUrl', selectedId), {method:'POST', body:JSON.stringify({version:state.selected.version})});
                    if (state.selected && state.selected.id === selectedId) state.selected = Object.assign({}, taken, {drafts:state.selected.drafts});
                }
                await api(endpoint('retryUrl', item.id), {method:'POST', body:JSON.stringify({request_id:attempt.id})});
                delete retryAttempts[item.id];
                if (state.selected && state.selected.id === selectedId) {
                    showThreadFeedback(t('mautic.inbox.ui.sent_again'));
                    await select(selectedId);
                }
                await loadList(false);
            } catch (error) {
                attempt.busy = false; showThreadError(error.message);
                if (button.isConnected) { button.disabled = false; button.textContent = t('mautic.inbox.ui.send_again'); }
            }
        }

        async function mutate(action, extra) {
            if (!state.selected) return;
            try {
                var selectedId = state.selected.id, revision = selectionRevision;
                var data = await api(endpoint('stateUrl', selectedId), {method:'POST', body:JSON.stringify(Object.assign({action:action, version:state.selected.version}, extra || {}))});
                if (revision !== selectionRevision) return;
                data.drafts = Object.assign({}, data.drafts, draftCache[selectedId] || {});
                state.selected = data; renderDetail(); await loadList(false); await refreshTimeline();
            } catch (error) { showThreadError(error.message); if (error.status === 409) select(state.selected.id); }
        }

        var timelineRefreshing = false;
        async function refreshTimeline() {
            if (!state.selected || timelineRefreshing) return;
            var id = state.selected.id, revision = selectionRevision;
            timelineRefreshing = true;
            try {
                var data = await api(endpoint('timelineUrl', id) + '?limit=100');
                if (revision !== selectionRevision || !state.selected || state.selected.id !== id) return;
                state.older = data.next_cursor; renderTimeline(data.items, false);
            } finally { timelineRefreshing = false; }
        }

        function showThreadError(message, source) {
            var error = document.createElement('div'); error.className = 'inbox-error'; error.textContent = message;
            if(source)error.dataset.source=source;
            var host=$('inbox-feedback');host.textContent='';host.appendChild(error);
        }

        function showThreadFeedback(message) {
            var feedback = document.createElement('div');
            feedback.className = 'inbox-feedback-success';
            feedback.textContent = message;
            var host = $('inbox-feedback');
            host.textContent = '';
            host.appendChild(feedback);
            window.setTimeout(function () {
                if (feedback.isConnected) feedback.remove();
            }, 3500);
        }

        async function send() {
            if (!state.selected || sending) return;
            var body = $('inbox-composer-text').value.trim(); if (!body) return;
            var selectedId = state.selected.id, mode = state.mode, isNote = mode === 'note', key = selectedId + ':' + mode;
            var payload = {body: body};
            if (!isNote && (!sendAttempts[key] || sendAttempts[key].body !== body)) sendAttempts[key] = {body:body, id:(window.crypto && crypto.randomUUID ? crypto.randomUUID().replace(/-/g, '') : String(Date.now()) + Math.random().toString(36).slice(2)).slice(0, 64)};
            if (!isNote) payload.request_id = sendAttempts[key].id;
            clearTimeout(draftTimers[key]);
            sending = true; updateComposer();
            try {
                $('inbox-feedback').textContent='';
                if (!isNote && state.selected && state.selected.id === selectedId && state.selected.can_take_and_reply) {
                    var taken = await api(endpoint('takeUrl', selectedId), {method:'POST',body:JSON.stringify({version:state.selected.version})});
                    if (state.selected && state.selected.id === selectedId) state.selected = Object.assign({}, taken, {drafts:state.selected.drafts});
                }
                if (draftWrites[key]) await draftWrites[key].catch(function () {});
                await api(endpoint(isNote ? 'noteUrl' : 'replyUrl', selectedId), {method:'POST', body:JSON.stringify(payload)});
                delete sendAttempts[key];
                draftCache[selectedId] = draftCache[selectedId] || {};
                if ((draftCache[selectedId][mode] || '').trim() === body) draftCache[selectedId][mode] = '';
                if (state.selected && state.selected.id === selectedId) {
                    if ((state.selected.drafts[mode] || '').trim() === body) state.selected.drafts[mode] = '';
                    if (state.mode === mode && $('inbox-composer-text').value.trim() === body) $('inbox-composer-text').value = '';
                    await refreshTimeline();
                }
                await loadList(false);
            } catch (error) { showThreadError(error.message); }
            sending = false; updateComposer();
        }

        var menuCatalogId = null;
        function updateResponseMenu() {
            var selected=state.selected, select=$('inbox-canned'), group=$('inbox-canned-group');
            group.disabled=!!(selected && selected.reply_blocked_reason);
            var whatsapp=selected && selected.channel==='whatsapp';
            var templates=$('inbox-template-group');templates.hidden=!whatsapp;templates.disabled=!whatsapp;
            if(!whatsapp){menuCatalogId=null;return;}
            if(menuCatalogId===selected.id)return;
            var id=selected.id;menuCatalogId=id;templates.textContent='';
            var loading=document.createElement('option');loading.textContent=t('mautic.inbox.template.loading');loading.disabled=true;templates.appendChild(loading);
            api(endpoint('templatesUrl',id)).then(function(result){
                if(menuCatalogId!==id || !state.selected || state.selected.id!==id)return;
                templates.textContent='';
                result.items.forEach(function(item){var option=document.createElement('option');option.value='template:'+item.id;option.textContent=item.name+' · '+item.language;option.disabled=!item.supported;templates.appendChild(option);});
                if(!result.items.length){var empty=document.createElement('option');empty.textContent=t('mautic.inbox.template.empty');empty.disabled=true;templates.appendChild(empty);}
            }).catch(function(){if(menuCatalogId!==id)return;templates.textContent='';var retry=document.createElement('option');retry.value='template:';retry.textContent=t('mautic.inbox.template.choose');templates.appendChild(retry);});
        }
        function updateComposer() {
            var note = state.mode === 'note';
            updateResponseMenu();
            if(note) { $('inbox-template-panel').classList.add('hide');root.querySelector('.inbox-composer').classList.remove('template-mode'); }
            var publicReply = !!(state.selected && state.selected.reply_public);
            var replyTab = root.querySelector('[data-mode="reply"]'); replyTab.textContent=publicReply?t("mautic.inbox.ui.public_reply_42dc43"):t("mautic.inbox.ui.private_reply_ecd924");
            $('inbox-composer-text').maxLength = !note && state.selected && state.selected.channel === 'instagram' ? 1000 : (!note && state.selected && state.selected.channel === 'facebook' ? 2000 : 4000);
            root.querySelector('.inbox-composer').classList.toggle('note-mode', note);
            root.querySelectorAll('.inbox-composer-tabs button').forEach(function (button) { button.classList.toggle('active', button.dataset.mode === state.mode); });
            $('inbox-canned').classList.toggle('hide', note);
            $('inbox-composer-text').placeholder = note ? t("mautic.inbox.ui.write_a_note_visible_only_to_your_team_10eb89") : (publicReply ? t("mautic.inbox.ui.write_a_public_reply_to_the_comment_251f01") : t("mautic.inbox.ui.write_a_private_reply_394bc1"));
            $('inbox-send').textContent = sending ? t("mautic.inbox.ui.sending_5e91dc") : (note ? t("mautic.inbox.ui.add_note_344d88") : (state.selected && state.selected.can_take_and_reply ? t("mautic.inbox.ui.assign_to_me_and_send_509661") : t("mautic.inbox.ui.send_reply_c50a43")));
            $('inbox-reply-hint').textContent = note ? t("mautic.inbox.ui.internal_note_only_your_team_will_see_this_text_6f8a19") : ((publicReply ? t("mautic.inbox.ui.public_reply_on_facebook_38b969") : '') + ((state.selected && state.selected.reply_hint) || ''));
            $('inbox-reply-hint').classList.toggle('blocked', !!(state.selected && state.selected.reply_blocked_reason && !note));
            $('inbox-send').disabled = sending || !state.selected || (!note && !state.selected.can_reply && !state.selected.can_take_and_reply) || (state.selected && state.selected.lifecycle === 'resolved');
        }

        function scheduleDraft() {
            updateComposer();
            if (!state.selected) return;
            var selectedId = state.selected.id, mode = state.mode, body = $('inbox-composer-text').value;
            var key = selectedId + ':' + mode;
            draftCache[selectedId] = draftCache[selectedId] || {};
            draftCache[selectedId][mode] = body;
            state.selected.drafts[mode] = body;
            $('inbox-draft-state').textContent = t("mautic.inbox.ui.saving_draft_1493aa"); clearTimeout(draftTimers[key]);
            draftTimers[key] = setTimeout(async function () {
                try { draftWrites[key] = (draftWrites[key] || Promise.resolve()).catch(function () {}).then(function () { return api(endpoint('draftUrl', selectedId), {method:'PUT', body:JSON.stringify({mode:mode, body:body})}); }); await draftWrites[key]; if (state.selected && state.selected.id === selectedId && state.mode === mode) $('inbox-draft-state').textContent = t("mautic.inbox.ui.draft_saved_a76c6d"); }
                catch (error) { if (state.selected && state.selected.id === selectedId && state.mode === mode) $('inbox-draft-state').textContent = t("mautic.inbox.ui.could_not_save_the_draft_a70018"); }
            }, 700);
        }


        function templateSelection() { return templateItems.find(function(item){return String(item.id)===$('inbox-template-select').value;}); }
        function templateValues() { var values={};$('inbox-template-fields').querySelectorAll('input').forEach(function(input){values[input.dataset.field]=input.value;});return values; }
        function previewTemplate() {
            var item=templateSelection(), values=templateValues();
            $('inbox-template-preview').textContent=item ? item.parts.map(function(part){return part.text.replace(/\{\{([A-Za-z0-9_]+)\}\}/g,function(match,token){return values[part.type+':'+token] || match;});}).join('\n\n') : '';
            $('inbox-template-status').textContent=templateBlocked || (item && !item.supported ? t('mautic.inbox.template.unsupported') : '');
            $('inbox-template-send').disabled=templateSending || !!templateBlocked || !item || !item.supported || item.fields.some(function(field){return !(values[field.key] || '').trim();}) || !state.selected || state.selected.lifecycle==='resolved' || (state.selected.assignee && state.selected.assignee.id!==Number(root.dataset.currentUser));
        }
        async function openTemplate(selectedTemplate) {
            if(!state.selected)return;var id=state.selected.id, revision=++templateRevision;templateOwner=id;
            $('inbox-template-panel').classList.remove('hide');root.querySelector('.inbox-composer').classList.add('template-mode');$('inbox-template-status').textContent=t('mautic.inbox.template.loading');$('inbox-template-send').disabled=true;
            $('inbox-template-select').textContent='';$('inbox-template-fields').textContent='';$('inbox-template-preview').textContent='';
            try {var result=await api(endpoint('templatesUrl',id));if(revision!==templateRevision || !state.selected || state.selected.id!==id)return;
                templateItems=result.items;templateBlocked=result.blocked_reason || null;
                var empty=document.createElement('option');empty.value='';empty.textContent=t('mautic.inbox.template.select');$('inbox-template-select').appendChild(empty);
                templateItems.forEach(function(item){var option=document.createElement('option');option.value=item.id;option.textContent=item.name+' · '+item.language; $('inbox-template-select').appendChild(option);});
                $('inbox-template-status').textContent=templateBlocked || (templateItems.length?'':t('mautic.inbox.template.empty'));
                if(selectedTemplate){$('inbox-template-select').value=selectedTemplate;$('inbox-template-select').dispatchEvent(new Event('change'));}
            }catch(error){if(revision===templateRevision)$('inbox-template-status').textContent=error.message;}
        }
        $('inbox-template-close').addEventListener('click',function(){++templateRevision;$('inbox-template-panel').classList.add('hide');root.querySelector('.inbox-composer').classList.remove('template-mode');});
        $('inbox-template-select').addEventListener('change',function(){
            var item=templateSelection();$('inbox-template-fields').textContent='';templateAttempt=null;
            if(item)item.fields.forEach(function(field){var label=document.createElement('label'),input=document.createElement('input');label.textContent=t('mautic.inbox.template.variable')+' '+t(field.component==='HEADER'?'mautic.inbox.template.header':'mautic.inbox.template.body')+' {{'+field.token+'}}';input.className='form-control';input.dataset.field=field.key;input.maxLength=1024;input.required=true;input.addEventListener('input',previewTemplate);label.appendChild(input);$('inbox-template-fields').appendChild(label);});previewTemplate();
        });
        $('inbox-template-send').addEventListener('click',async function(){
            var item=templateSelection();if(!item || !state.selected || this.disabled || templateSending)return;
            if(!window.confirm(t('mautic.inbox.template.confirm')))return;
            var id=state.selected.id, payload={template_id:item.id,variables:templateValues()}, signature=JSON.stringify([id,payload]);
            if(!templateAttempt || templateAttempt.signature!==signature)templateAttempt={signature:signature,id:crypto.randomUUID().replace(/-/g,'')};payload.request_id=templateAttempt.id;
            templateSending=true;previewTemplate();this.textContent=t('mautic.inbox.template.sending');
            try {
                if(!state.selected.assignee) {var taken=await api(endpoint('takeUrl',id),{method:'POST',body:JSON.stringify({version:state.selected.version})});if(state.selected && state.selected.id===id)state.selected=Object.assign({},taken,{drafts:state.selected.drafts});}
                await api(endpoint('replyUrl',id),{method:'POST',body:JSON.stringify(payload)});templateAttempt=null;
                if(state.selected && state.selected.id===id){$('inbox-template-panel').classList.add('hide');root.querySelector('.inbox-composer').classList.remove('template-mode');$('inbox-feedback').textContent=t('mautic.inbox.template.queued');await refreshTimeline();}await loadList(false);
            }catch(error){if(state.selected && state.selected.id===id)$('inbox-template-status').textContent=error.message;}
            finally{templateSending=false;$('inbox-template-send').textContent=t('mautic.inbox.template.send');var notice=$('inbox-template-status').textContent;previewTemplate();$('inbox-template-status').textContent=notice;}
        });

        var updating = false, pendingUpdate = false;
        async function poll() {
            if (!root.isConnected) return;
            if (updating) { pendingUpdate = true; return; }
            updating = true;
            try {
                var selectedId = state.selected && state.selected.id, revision = selectionRevision;
                var q = new URLSearchParams({since: state.since}); if(notificationCursor!==null)q.set('notification_cursor',notificationCursor); if (selectedId) q.set('state_id', selectedId);
                var data = await api(root.dataset.pollUrl + '?' + q.toString()); state.since = data.next_since;
                if(!root.isConnected)return;
                notificationCursor=data.notification_cursor;alerts.receive(data.notifications || []);
                if(data.has_more || data.notifications_more)pendingUpdate=true;
                await loadList(false, true);
                if (selectedId && revision === selectionRevision) {
                    var detail = await api(endpoint('detailUrl', selectedId));
                    if (revision === selectionRevision) {
                        var body = $('inbox-composer-text').value;
                        detail.drafts = Object.assign({}, detail.drafts, draftCache[selectedId] || {});
                        state.selected = detail; renderDetail(); $('inbox-composer-text').value = body;
                        await refreshTimeline();
                    }
                }
            } catch (error) { /* A próxima atualização tenta novamente sem interromper o editor. */ }
            updating = false;
            if (pendingUpdate) { pendingUpdate = false; poll(); }
        }

        function renderCannedResponses(items) {
            cannedSettings = items.filter(function(item){ return item.enabled !== false; });
            var group = $('inbox-canned-group');
            group.textContent = '';
            cannedSettings.forEach(function(item){
                var option = document.createElement('option');
                option.value = item.id;
                option.textContent = item.name;
                option.dataset.body = item.body;
                group.appendChild(option);
            });

            var list = $('inbox-canned-list');
            if (!list) return;
            list.textContent = '';
            if (!cannedSettings.length) {
                var empty = document.createElement('p');
                empty.className = 'inbox-canned-empty';
                empty.textContent = t('mautic.inbox.settings.canned_empty');
                list.appendChild(empty);
                return;
            }
            cannedSettings.forEach(function(item){
                var row = document.createElement('div');
                row.className = 'inbox-canned-item';
                var copy = document.createElement('div');
                var name = document.createElement('strong');
                var body = document.createElement('p');
                name.textContent = item.name;
                body.textContent = item.body;
                copy.appendChild(name);
                copy.appendChild(body);
                var actions = document.createElement('div');
                actions.className = 'inbox-canned-item-actions';
                var edit = document.createElement('button');
                edit.type = 'button';
                edit.className = 'btn btn-default btn-sm';
                edit.textContent = t('mautic.inbox.settings.canned_edit');
                edit.addEventListener('click', function(){ openCannedForm(item); });
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'btn btn-link btn-sm inbox-canned-delete';
                remove.textContent = t('mautic.inbox.settings.canned_delete');
                remove.addEventListener('click', function(){ deleteCannedResponse(item); });
                actions.appendChild(edit);
                actions.appendChild(remove);
                row.appendChild(copy);
                row.appendChild(actions);
                list.appendChild(row);
            });
        }

        function cannedFeedback(message, error) {
            var feedback = $('inbox-canned-feedback');
            if (!feedback) return;
            feedback.textContent = message || '';
            feedback.classList.toggle('error', !!error);
        }

        function openCannedForm(item) {
            var form = $('inbox-canned-form');
            if (!form) return;
            form.reset();
            form.elements.id.value = item ? item.id : '';
            form.elements.name.value = item ? item.name : '';
            form.elements.body.value = item ? item.body : '';
            $('inbox-canned-form-title').textContent = item ? t('mautic.inbox.settings.canned_edit') : t('mautic.inbox.settings.canned_new');
            $('inbox-canned-save').textContent = item ? t('mautic.inbox.settings.canned_save_changes') : t('mautic.inbox.ui.save_response_8d37d6');
            form.classList.remove('hide');
            form.elements.name.focus();
        }

        function closeCannedForm() {
            var form = $('inbox-canned-form');
            if (!form) return;
            form.reset();
            form.classList.add('hide');
        }

        async function deleteCannedResponse(item) {
            if (!window.confirm(t('mautic.inbox.settings.canned_confirm_delete'))) return;
            try {
                cannedFeedback('');
                var data = await api(endpoint('cannedItemUrl', item.id), {method:'DELETE', body:'{}'});
                renderCannedResponses(data.items || []);
                closeCannedForm();
                cannedFeedback(t('mautic.inbox.settings.canned_deleted'));
            } catch (error) { cannedFeedback(error.message, true); }
        }

        root.querySelectorAll('.inbox-queues button').forEach(function (button) { button.addEventListener('click', function () { root.querySelectorAll('.inbox-queues button').forEach(function (b) { b.classList.remove('active'); }); button.classList.add('active'); state.queue = button.dataset.queue; state.next = null; loadList(false); }); });
        ['inbox-lifecycle','inbox-channel','inbox-needs-response'].forEach(function (id) { $(id).addEventListener('change', function () { loadList(false); }); });
        var searchTimer; $('inbox-search').addEventListener('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(function () { loadList(false); }, 350); });
        $('inbox-more').addEventListener('click', function () { loadList(true); });
        $('inbox-older').addEventListener('click', async function () { if (!state.older || !state.selected) return; var revision = selectionRevision; var data = await api(endpoint('timelineUrl', state.selected.id) + '?limit=100&before=' + encodeURIComponent(state.older)); if (revision !== selectionRevision) return; state.older = data.next_cursor; renderTimeline(data.items, true); });
        $('inbox-take').addEventListener('click', async function () { if (!state.selected) return; try { var revision = selectionRevision, selectedId = state.selected.id; var data = await api(endpoint('takeUrl', selectedId), {method:'POST', body:JSON.stringify({version:state.selected.version})}); if (revision !== selectionRevision) return; data.drafts = Object.assign({}, data.drafts, draftCache[selectedId] || {}); state.selected = data; renderDetail(); loadList(false); } catch (error) { showThreadError(error.message); } });
        $('inbox-transfer').addEventListener('change', function () { if (this.value === 'unassign') mutate('unassign'); else if (this.value) mutate('transfer', {target_user_id:Number(this.value)}); this.value = ''; });
        $('inbox-resolve').addEventListener('click', function () { mutate('resolve'); }); $('inbox-reopen').addEventListener('click', function () { mutate('reopen'); });
        $('inbox-snooze').addEventListener('click', function () { var value = $('inbox-snooze-duration').value, until = new Date(); if (value === 'tomorrow') { until.setDate(until.getDate()+1); until.setHours(9,0,0,0); } else until = new Date(until.getTime()+Number(value)*60000); mutate('snooze', {until:until.toISOString()}); });
        $('inbox-contact-toggle').addEventListener('click', function () { var panel = $('inbox-contact-panel'); if (window.innerWidth >= 1200) { panel.classList.toggle('closed'); this.setAttribute('aria-expanded', panel.classList.contains('closed') ? 'false' : 'true'); } else { panel.classList.toggle('open'); this.setAttribute('aria-expanded', panel.classList.contains('open') ? 'true' : 'false'); } });
        root.querySelectorAll('.inbox-composer-tabs button').forEach(function (button) { button.addEventListener('click', function () { if (state.selected) state.selected.drafts[state.mode] = $('inbox-composer-text').value; state.mode = button.dataset.mode; if (state.selected) $('inbox-composer-text').value = state.selected.drafts[state.mode] || ''; updateComposer(); }); });
        $('inbox-composer-text').addEventListener('input', scheduleDraft); $('inbox-send').addEventListener('click', send);
        $('inbox-canned').addEventListener('change', function () { var option = this.options[this.selectedIndex]; if(this.value.indexOf('template:')===0){var selectedTemplate=this.value.slice(9);this.value='';openTemplate(selectedTemplate);return;} if(state.selected && state.selected.reply_blocked_reason){this.value='';return;} if (option.dataset.body) { var area = $('inbox-composer-text'); area.value += (area.value ? '\n' : '') + option.dataset.body; area.dispatchEvent(new Event('input')); } this.value = ''; });
        root.querySelectorAll('.inbox-tabs button').forEach(function(button){button.addEventListener('click',function(){root.querySelectorAll('.inbox-tabs button').forEach(function(b){b.classList.remove('active');});button.classList.add('active');var view=button.dataset.view, staticView=view==='automation'||view==='settings';root.querySelectorAll('[data-inbox-only]').forEach(function(el){el.classList.toggle('hide',staticView);});$('inbox-automation').classList.toggle('hide',view!=='automation');$('inbox-settings').classList.toggle('hide',view!=='settings');if(!staticView){state.view=view;state.selected=null;++selectionRevision;root.classList.remove('has-selection');$('inbox-selected').classList.add('hide');$('inbox-empty').classList.remove('hide');$('inbox-list-heading').textContent=state.view==='comments'?t("mautic.inbox.ui.comments_6fe305"):t("mautic.inbox.ui.conversations_86d0e6");loadList(false);}});});
        $('inbox-filter-toggle').addEventListener('click',function(){var panel=$('inbox-filter-panel');panel.classList.toggle('hide');this.setAttribute('aria-expanded',panel.classList.contains('hide')?'false':'true');});
        $('inbox-back').addEventListener('click',function(){root.classList.remove('has-selection');});
        $('inbox-composer-text').addEventListener('keydown',function(event){if((event.metaKey||event.ctrlKey)&&event.key==='Enter'){event.preventDefault();if(!$('inbox-send').disabled)send();}});
        if ($('inbox-canned-form')) {
            $('inbox-canned-new').addEventListener('click', function(){ cannedFeedback(''); openCannedForm(null); });
            $('inbox-canned-cancel').addEventListener('click', closeCannedForm);
            $('inbox-canned-form').addEventListener('submit', async function (event) {
                event.preventDefault();
                var form = new FormData(this), id = form.get('id');
                try {
                    cannedFeedback('');
                    var data = await api(id ? endpoint('cannedItemUrl', id) : root.dataset.cannedUrl, {method:id?'PUT':'POST', body:JSON.stringify({name:form.get('name'), body:form.get('body')})});
                    renderCannedResponses(data.items || []);
                    closeCannedForm();
                    cannedFeedback(t('mautic.inbox.settings.canned_saved'));
                } catch (error) { cannedFeedback(error.message, true); }
            });
        }
        renderCannedResponses(cannedSettings);

        loadList(false);
        poll();
        var aiActivityTimer=setInterval(function(){if(root.isConnected&&state.selected&&!document.hidden)renderAi(false);},1500);
        var conversationActivityTimer=setInterval(function(){if(root.isConnected&&state.selected&&!document.hidden)refreshTimeline().catch(function(){});},2000);
        var stream, fallbackTimer, reconnectTimer, lastVersion = null;
        function fallback() { if (!fallbackTimer) fallbackTimer = setInterval(function(){if(root.isConnected) poll();}, 20000); }
        if (window.EventSource && root.dataset.streamUrl) {
            stream = new EventSource(root.dataset.streamUrl);
            stream.onopen = function(){ clearTimeout(reconnectTimer);reconnectTimer=null;$('inbox-live-status').textContent=t("mautic.inbox.ui.live_c81a1f"); clearInterval(fallbackTimer); fallbackTimer=null; };
            stream.addEventListener('inbox', function(event){ var data=JSON.parse(event.data); if(data.version!==lastVersion) poll(); lastVersion=data.version; });
            stream.onerror = function(){ if(!reconnectTimer)reconnectTimer=setTimeout(function(){reconnectTimer=null;if(stream.readyState!==EventSource.OPEN){$('inbox-live-status').textContent=t("mautic.inbox.ui.reconnecting_5c2ce7");fallback();}},15000); };
        } else { $('inbox-live-status').textContent=t("mautic.inbox.ui.automatic_updates_8a484f"); fallback(); }
        var cleanup = new MutationObserver(function(){if(!root.isConnected){alerts.dispose();if(stream)stream.close();clearTimeout(reconnectTimer);clearInterval(fallbackTimer);clearInterval(aiActivityTimer);clearInterval(conversationActivityTimer);cleanup.disconnect();}});
        cleanup.observe(document.body,{childList:true,subtree:true});
    }

    if (window.Mautic) window.Mautic.inboxOnLoad = boot;
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
    document.addEventListener('mauticPageContentLoaded', boot);
}());
