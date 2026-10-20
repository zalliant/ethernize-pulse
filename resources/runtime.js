/*!
 * Ethernize Pulse runtime. Plain JavaScript, no dependencies, no build step.
 *
 * Markup contract (see docs/pulse.md):
 *   pulse:click="action"  pulse:submit="action"  pulse:change="action"  pulse:input="action"
 *   pulse:arg, pulse:arg2 ... arguments of the action (strings; the server converts them)
 *   pulse:model="property" sends the field value with the next action
 *   pulse:loading          element shown (hidden attribute removed) while a request is running
 *   pulse:debounce="ms"    delay for pulse:input (default 150)
 *
 * Actions run one at a time per component, in the order they were triggered. Every click is sent;
 * only a form submission identical to one that is already pending is ignored (double submit).
 */
(function () {
  'use strict';

  if (window.Pulse) {
    return;
  }

  var PROTOCOL = 1;
  var TIMEOUT_MS = 30000;
  var LOADING_DELAY_MS = 120;
  var SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];
  var URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'poster', 'cite', 'background', 'xlink:href'];
  var states = new WeakMap();
  var sent = {}; // values the running request carried, to avoid overwriting newer typing

  function stateOf(root) {
    var state = states.get(root);

    if (!state) {
      state = { queue: [], running: null, timers: new WeakMap() };
      states.set(root, state);
    }

    return state;
  }

  // ---------------------------------------------------------------- safety

  function safeUrl(value) {
    var cleaned = String(value).replace(/[\u0000- \u007f]/g, '');
    var scheme = /^([a-zA-Z][a-zA-Z0-9+.\-]*):/.exec(cleaned);

    if (scheme) {
      return SAFE_SCHEMES.indexOf(scheme[1].toLowerCase()) !== -1;
    }

    return !/^[^\/?#]*:/.test(cleaned);
  }

  function attributeAllowed(name, value) {
    var lower = name.toLowerCase();

    if (lower.indexOf('on') === 0 || lower === 'srcdoc') {
      return false;
    }

    return URL_ATTRIBUTES.indexOf(lower) === -1 || safeUrl(value);
  }

  function setAttribute(element, name, value) {
    if (!attributeAllowed(name, value)) {
      value = URL_ATTRIBUTES.indexOf(name.toLowerCase()) !== -1 ? '#' : null;
    }

    if (value === null) {
      return;
    }

    try {
      element.setAttribute(name, value);
    } catch (error) {
      /* invalid attribute name: ignore */
    }
  }

  function sanitize(node) {
    if (node.nodeType !== 1) {
      return node;
    }

    Array.prototype.slice.call(node.attributes).forEach(function (attribute) {
      if (!attributeAllowed(attribute.name, attribute.value)) {
        if (URL_ATTRIBUTES.indexOf(attribute.name.toLowerCase()) !== -1) {
          node.setAttribute(attribute.name, '#');
        } else {
          node.removeAttribute(attribute.name);
        }
      }
    });

    Array.prototype.forEach.call(node.children, sanitize);

    return node;
  }

  function parse(html, root) {
    var template = document.createElement('template');
    template.innerHTML = html;

    var nodes = Array.prototype.map.call(template.content.childNodes, function (node) {
      return sanitize(node);
    });

    return root ? keepChildren(nodes, root) : nodes;
  }

  // A child component the browser already displays is not sent again: the markup carries a
  // placeholder, and the live element (with its state) takes its place.
  function keepChildren(nodes, root) {
    return nodes.map(function (node) {
      if (node.nodeType !== 1) {
        return node;
      }

      var placeholders = Array.prototype.slice.call(node.querySelectorAll('[data-pulse-ref]'));

      if (node.hasAttribute('data-pulse-ref')) {
        placeholders.unshift(node);
      }

      placeholders.forEach(function (placeholder) {
        var live = liveChild(root, placeholder.getAttribute('data-pulse-ref'));

        if (!live) {
          var error = new Error('missing child component');
          error.code = 'child';
          throw error;
        }

        if (placeholder === node) {
          node = live;
        } else {
          placeholder.parentNode.replaceChild(live, placeholder);
        }
      });

      return node;
    });
  }

  function liveChild(root, id) {
    if (!/^[a-f0-9]{32}$/.test(id)) {
      return null;
    }

    var all = root.querySelectorAll('[data-pulse-id]');

    for (var i = 0; i < all.length; i++) {
      if (all[i].getAttribute('data-pulse-id') === id) {
        return all[i];
      }
    }

    return null;
  }

  // ---------------------------------------------------------------- index

  function Index() {
    this.ranges = Object.create(null);
    this.anchors = Object.create(null);
  }

  function isNestedRoot(element, root) {
    return element !== root && element.hasAttribute('data-pulse-id');
  }

  function buildIndex(root) {
    var index = new Index();
    var stack = [];

    (function walk(parent) {
      var child = parent.firstChild;

      while (child) {
        if (child.nodeType === 8) {
          mark(child, stack, index);
        } else if (child.nodeType === 1) {
          if (child.hasAttribute('data-pa')) {
            index.anchors[child.getAttribute('data-pa')] = child;
          }

          if (!isNestedRoot(child, root)) {
            walk(child);
          }
        }

        child = child.nextSibling;
      }
    })(root);

    return index;
  }

  function mark(comment, stack, index) {
    var data = comment.data;

    if (data.charAt(0) === '/' && data.charAt(1) === 'p') {
      var open = stack.pop();

      if (open) {
        open.end = comment;

        if (open.kind === 'i') {
          var list = stack[stack.length - 1];

          if (list && list.kind === 'l') {
            list.items.push(open);
          }
        } else {
          index.ranges[open.id] = open;
        }
      }

      return;
    }

    var match = /^p([thbnlio]):(.+)$/.exec(data);

    if (!match) {
      return;
    }

    var entry = { kind: match[1], id: match[2], start: comment, end: null, items: [] };

    if (entry.kind === 'i') {
      var parent = stack[stack.length - 1];
      entry.key = match[2];
      entry.id = (parent ? parent.id : '') + '/' + match[2];
    }

    stack.push(entry);
  }

  function nodesBetween(start, end) {
    var nodes = [];

    for (var node = start.nextSibling; node && node !== end; node = node.nextSibling) {
      nodes.push(node);
    }

    return nodes;
  }

  // ---------------------------------------------------------------- morph

  function keyOf(element) {
    return element.getAttribute('id') || element.getAttribute('data-pulse-id');
  }

  function sameKind(a, b) {
    if (a.nodeType !== b.nodeType) {
      return false;
    }

    if (a.nodeType === 1) {
      return a.tagName === b.tagName && keyOf(a) === keyOf(b);
    }

    return a.nodeType === 3 || (a.nodeType === 8 && a.data === b.data);
  }

  function syncAttributes(target, source) {
    Array.prototype.slice.call(target.attributes).forEach(function (attribute) {
      if (!source.hasAttribute(attribute.name)) {
        target.removeAttribute(attribute.name);
      }
    });

    Array.prototype.forEach.call(source.attributes, function (attribute) {
      if (target.getAttribute(attribute.name) !== attribute.value) {
        setAttribute(target, attribute.name, attribute.value);
      }
    });
  }

  // A focused field keeps what the user typed, unless it still holds exactly what the request sent.
  function canWriteValue(element) {
    if (element !== document.activeElement) {
      return true;
    }

    var name = element.getAttribute('pulse:model');

    return name !== null && Object.prototype.hasOwnProperty.call(sent, name) && String(sent[name]) === element.value;
  }

  function syncFormState(target, source) {
    if (!canWriteValue(target)) {
      return;
    }

    if (target.tagName === 'INPUT') {
      if (target.type === 'checkbox' || target.type === 'radio') {
        target.checked = source.hasAttribute('checked');
      } else {
        target.value = source.getAttribute('value') || '';
      }
    } else if (target.tagName === 'TEXTAREA') {
      target.value = source.textContent;
    } else if (target.tagName === 'OPTION') {
      target.selected = source.hasAttribute('selected');
    }
  }

  function morphNode(target, source) {
    if (target.nodeType === 3) {
      if (target.nodeValue !== source.nodeValue) {
        target.nodeValue = source.nodeValue;
      }

      return;
    }

    if (target.nodeType !== 1) {
      return;
    }

    syncAttributes(target, source);
    reconcile(target, Array.prototype.slice.call(target.childNodes), Array.prototype.slice.call(source.childNodes), null);
    syncFormState(target, source);
  }

  function reconcile(parent, oldNodes, newNodes, before) {
    for (var i = 0; i < newNodes.length; i++) {
      var current = oldNodes[i];
      var next = newNodes[i];

      if (current === next) {
        continue; // a live child component moved into the new markup
      }

      if (!current) {
        parent.insertBefore(next, before);
      } else if (sameKind(current, next)) {
        morphNode(current, next);
      } else {
        parent.replaceChild(next, current);
      }
    }

    for (var j = newNodes.length; j < oldNodes.length; j++) {
      parent.removeChild(oldNodes[j]);
    }
  }

  function morphRange(start, end, newNodes) {
    reconcile(start.parentNode, nodesBetween(start, end), newNodes, end);
  }

  // ---------------------------------------------------------------- patches

  function need(entry, id) {
    if (!entry) {
      throw new Error('missing region ' + id);
    }

    return entry;
  }

  function applyList(root, index, patch) {
    var list = need(index.ranges[patch.id], patch.id);
    var items = Object.create(null);

    list.items.forEach(function (item) {
      items[item.key] = item;
    });

    patch.remove.forEach(function (key) {
      var item = need(items[key], patch.id + '/' + key);
      nodesBetween(item.start, item.end).concat([item.start, item.end]).forEach(function (node) {
        node.parentNode.removeChild(node);
      });
      delete items[key];
    });

    patch.place.forEach(function (placement) {
      var anchor = placement.before === null ? list.end : need(items[placement.before], placement.before).start;
      var parent = anchor.parentNode;

      if (typeof placement.html === 'string') {
        var nodes = parse('<!--pi:' + placement.key + '-->' + placement.html + '<!--/pi-->', root);
        nodes.forEach(function (node) {
          parent.insertBefore(node, anchor);
        });
        items[placement.key] = { key: placement.key, start: nodes[0], end: nodes[nodes.length - 1] };
      } else {
        var item = need(items[placement.key], patch.id + '/' + placement.key);
        var moving = [item.start].concat(nodesBetween(item.start, item.end), [item.end]);
        moving.forEach(function (node) {
          parent.insertBefore(node, anchor);
        });
      }
    });

    // Items that stayed but whose markup changed: morph their content, keeping the item's nodes.
    (patch.replace || []).forEach(function (change) {
      var item = need(items[change.key], patch.id + '/' + change.key);
      morphRange(item.start, item.end, parse(change.html, root));
    });
  }

  function applyPatches(root, patches) {
    var index = buildIndex(root);

    patches.forEach(function (patch) {
      switch (patch.op) {
        case 'text': {
          var text = need(index.ranges[patch.id], patch.id);
          nodesBetween(text.start, text.end).forEach(function (node) {
            node.parentNode.removeChild(node);
          });
          text.start.parentNode.insertBefore(document.createTextNode(patch.value), text.end);
          break;
        }
        case 'html':
        case 'block': {
          var range = need(index.ranges[patch.id], patch.id);
          morphRange(range.start, range.end, parse(patch.html, root));
          break;
        }
        case 'attr': {
          var element = need(index.anchors[patch.id], patch.id);
          Object.keys(patch.set).forEach(function (name) {
            setAttribute(element, name, patch.set[name]);

            if (name === 'checked' && 'checked' in element) {
              element.checked = true;
            }

            if (name === 'value' && canWriteValue(element) && 'value' in element) {
              element.value = patch.set[name];
            }
          });
          patch.remove.forEach(function (name) {
            element.removeAttribute(name);

            if (name === 'checked' && 'checked' in element) {
              element.checked = false;
            }
          });
          break;
        }
        case 'list':
          applyList(root, index, patch);
          break;
        default:
          throw new Error('unknown patch ' + patch.op);
      }
    });
  }

  function applyFull(root, html) {
    // Parse first: it moves the children the page already shows out of the old markup.
    var fresh = parse(html, root);

    reconcile(root, Array.prototype.slice.call(root.childNodes), fresh, null);
  }

  // ---------------------------------------------------------------- requests

  function collectInputs(root) {
    var inputs = {};

    Array.prototype.forEach.call(root.querySelectorAll('[pulse\\:model]'), function (field) {
      if (field.closest('[data-pulse-id]') !== root) {
        return;
      }

      var name = field.getAttribute('pulse:model');

      if (field.type === 'checkbox') {
        inputs[name] = field.checked;
      } else if (field.type === 'radio') {
        if (field.checked) {
          inputs[name] = field.value;
        }
      } else {
        inputs[name] = field.value;
      }
    });

    return inputs;
  }

  // Form fields bound with pulse:model take the value the server holds after the action.
  function syncModels(root, model) {
    Array.prototype.forEach.call(root.querySelectorAll('[pulse\\:model]'), function (field) {
      var name = field.getAttribute('pulse:model');

      if (field.closest('[data-pulse-id]') !== root || !Object.prototype.hasOwnProperty.call(model, name) || !canWriteValue(field)) {
        return;
      }

      var value = model[name];

      if (field.type === 'checkbox') {
        field.checked = value === true;
      } else if (field.type === 'radio') {
        field.checked = String(field.value) === String(value);
      } else {
        field.value = value === null ? '' : String(value);
      }
    });
  }

  function applyBody(root, body) {
    keepFocus(root, function () {
      if (body.mode === 'full') {
        applyFull(root, body.html);
      } else if (body.mode === 'patch') {
        applyPatches(root, body.patches);
      }

      if (body.model) {
        syncModels(root, body.model);
      }
    });
  }

  // The loading state (class, aria-busy, [pulse:loading] elements) appears only if a request takes
  // longer than a moment. Most actions finish before that, and then the page is not touched for it.
  function startLoading(root, state) {
    if (state.loading || state.loadingTimer) {
      return;
    }

    var delay = parseInt(root.getAttribute('data-pulse-loading-delay') || '', 10);

    state.loadingTimer = setTimeout(function () {
      state.loadingTimer = null;
      state.loading = true;
      setLoading(root, true);
    }, isNaN(delay) ? LOADING_DELAY_MS : delay);
  }

  function stopLoading(root, state) {
    if (state.loadingTimer) {
      clearTimeout(state.loadingTimer);
      state.loadingTimer = null;
    }

    if (state.loading) {
      state.loading = false;
      setLoading(root, false);
    }
  }

  function setLoading(root, loading) {
    root.classList.toggle('pulse-loading', loading);

    if (loading) {
      root.setAttribute('aria-busy', 'true');
    } else {
      root.removeAttribute('aria-busy');
    }

    Array.prototype.forEach.call(root.querySelectorAll('[pulse\\:loading]'), function (element) {
      element.hidden = !loading;
    });
  }

  function emit(root, name, detail) {
    return root.dispatchEvent(new CustomEvent('pulse:' + name, { bubbles: true, cancelable: true, detail: detail }));
  }

  function keepFocus(root, callback) {
    var active = document.activeElement;
    var inside = active && active !== document.body && root.contains(active) ? active : null;
    var selection = inside && typeof inside.selectionStart === 'number' ? [inside.selectionStart, inside.selectionEnd] : null;

    callback();

    if (inside && inside.isConnected && document.activeElement !== inside) {
      inside.focus({ preventScroll: true });

      if (selection) {
        try {
          inside.setSelectionRange(selection[0], selection[1]);
        } catch (error) {
          /* element does not support selection */
        }
      }
    }
  }

  function request(root, job) {
    var controller = new AbortController();
    job.sent = collectInputs(root);
    var timer = setTimeout(function () {
      controller.abort();
    }, TIMEOUT_MS);

    return fetch(root.getAttribute('data-pulse-endpoint'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Pulse': '1', Accept: 'application/json' },
      body: JSON.stringify({
        v: PROTOCOL,
        snapshot: root.getAttribute('data-pulse-snapshot'),
        action: job.action,
        args: job.args,
        inputs: job.sent,
        full: job.full === true,
        remount: job.remount === true,
        key: job.key
      }),
      signal: controller.signal
    }).then(function (response) {
      clearTimeout(timer);

      return response.text().then(function (text) {
        var body = null;

        try {
          body = JSON.parse(text);
        } catch (error) {
          /* not JSON */
        }

        var timing = /pulse;dur=([0-9.]+)/.exec(response.headers.get('Server-Timing') || '');

        return { status: response.status, body: body, bytes: text.length, serverMs: timing ? parseFloat(timing[1]) : null };
      });
    }, function (error) {
      clearTimeout(timer);
      throw error;
    });
  }

  function run(root) {
    var state = stateOf(root);

    if (state.running || state.queue.length === 0) {
      return;
    }

    var job = state.queue.shift();
    state.running = job;
    startLoading(root, state);

    var started = performance.now();

    request(root, job).then(function (result) {
      var body = result.body;
      var received = performance.now();

      if (result.status === 200 && body && body.v === PROTOCOL && typeof body.snapshot === 'string') {
        root.removeAttribute('data-pulse-error');
        root.setAttribute('data-pulse-snapshot', body.snapshot);

        sent = job.sent || {};

        try {
          applyBody(root, body);
        } catch (error) {
          // The DOM does not match what the server assumed: ask once for the whole component.
          if (job.resync) {
            emit(root, 'error', { code: 'sync', status: 0, message: String(error && error.message ? error.message : error) });
          } else {
            // A child the server meant to keep is gone from the page: ask for the whole component, children included.
            state.queue.unshift({ action: '$refresh', args: [], full: true, resync: true, remount: error && error.code === 'child' });
          }
        }

        sent = {};

        emit(root, 'updated', {
          mode: body.mode,
          strategy: body.strategy,
          action: job.action,
          timing: { roundTripMs: received - started, serverMs: result.serverMs, applyMs: performance.now() - received, bytes: result.bytes }
        });

        return;
      }

      state.queue.length = 0;
      var code = body && body.error ? (body.error.code || String(result.status)) : 'server';
      root.setAttribute('data-pulse-error', code);

      if (['stale', 'expired', 'invalid_snapshot'].indexOf(code) !== -1) {
        if (emit(root, 'stale', { code: code })) {
          window.location.reload();
        }
      } else {
        emit(root, 'error', { code: code, status: result.status, message: body && body.error ? body.error.message : '' });
      }
    }, function (error) {
      state.queue.length = 0;
      root.setAttribute('data-pulse-error', 'network');
      emit(root, 'error', { code: 'network', status: 0, message: String(error && error.message ? error.message : error) });
    }).catch(function () {
      state.queue.length = 0;
    }).then(function () {
      state.running = null;

      if (state.queue.length === 0) {
        stopLoading(root, state);
      }

      run(root);
    });
  }

  // A random identifier for each call, sent with it (see Component::actionKey() on the server).
  function newKey() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }

    return Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
  }

  function enqueue(root, action, args, dedupe) {
    var state = stateOf(root);
    var key = action + '\u0000' + JSON.stringify(args);
    var same = function (job) {
      return job.action + '\u0000' + JSON.stringify(job.args) === key;
    };

    if (dedupe && (state.queue.some(same) || (state.running && same(state.running)))) {
      return;
    }

    state.queue.push({ action: action, args: args, key: newKey() });
    run(root);
  }

  // ---------------------------------------------------------------- events

  function argumentsOf(element) {
    var args = [];
    var first = element.getAttribute('pulse:arg');

    if (first === null) {
      return args;
    }

    args.push(first);

    for (var i = 2; i <= 8; i++) {
      var value = element.getAttribute('pulse:arg' + i);

      if (value === null) {
        break;
      }

      args.push(value);
    }

    return args;
  }

  function handle(event, name) {
    var attribute = 'pulse:' + name;

    for (var element = event.target; element && element.nodeType === 1; element = element.parentNode) {
      if (!element.hasAttribute(attribute)) {
        continue;
      }

      var root = element.closest('[data-pulse-id]');

      if (!root) {
        return;
      }

      if (name === 'click' && (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)) {
        return;
      }

      if (name === 'click' || name === 'submit') {
        event.preventDefault();
      }

      var action = element.getAttribute(attribute);

      if (name === 'input') {
        var state = stateOf(root);
        var delay = parseInt(element.getAttribute('pulse:debounce') || '150', 10);
        clearTimeout(state.timers.get(element));
        state.timers.set(element, setTimeout(function () {
          enqueue(root, action, argumentsOf(element), false);
        }, delay));

        return;
      }

      enqueue(root, action, argumentsOf(element), name === 'submit');

      return;
    }
  }

  ['click', 'submit', 'change', 'input'].forEach(function (name) {
    document.addEventListener(name, function (event) {
      handle(event, name);
    });
  });

  window.Pulse = {
    version: PROTOCOL,
    /** Applies a server response (snapshot already stored) to a component. Used by tools and benchmarks. */
    apply: function (root, body) {
      root.setAttribute('data-pulse-snapshot', body.snapshot);
      applyBody(root, body);
    },
    /** Runs an action programmatically: Pulse.call(rootElement, 'name', ['arg']). */
    call: function (root, action, args) {
      enqueue(root, action, args || [], false);
    },
    /** Resolves when the component has no pending requests. */
    idle: function (root) {
      return new Promise(function (resolve) {
        (function check() {
          var state = stateOf(root);

          if (!state.running && state.queue.length === 0) {
            resolve();
          } else {
            setTimeout(check, 10);
          }
        })();
      });
    }
  };
})();
