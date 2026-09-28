/**
 * Delay bootstrap: the inline script Delay JS prints once, on wp_footer.
 *
 * It waits for the visitor's first interaction (or the failsafe timeout),
 * then runs the replay: every delayed script is put back, in page order,
 * and each one hears DOMContentLoaded, readystatechange and load once, as
 * if it had run while the page loaded (#494).
 *
 * This is the readable source. `npm run build` minifies it into
 * assets/delay-bootstrap.min.js with scripts/minify-delay-bootstrap.mjs,
 * which only minifies: it adds no wrapper, helper or strict-mode line.
 * Minify_Filters::print_delay_bootstrap() inlines the built copy and puts
 * the timeout in place of XSPEED_DELAY_TIMEOUT at the very end. Edit this
 * file, rebuild, and commit both; a unit test fails when the built copy is
 * stale. Behaviour is pinned in a real browser by
 * tests/e2e/85-delay-js-replay-harness.spec.ts, and the choice to rename
 * listeners rather than fire the real events again is ADR 0001.
 *
 * Why the replay renames listeners. It runs after the page has loaded, so
 * DOMContentLoaded and load have already fired, and a delayed script that
 * sets itself up in one of those listeners is never called. Dispatching the
 * real events again would run every eager script's handlers a second time.
 * Instead, while the replay runs, addEventListener/removeEventListener on
 * document and window file the three lifecycle events under private xs-*
 * names. Only code running during the replay registers there, so
 * dispatching the private names reaches exactly the replayed scripts. The
 * dispatched event reports the real `type`: shared handlers and jQuery's
 * dispatcher look handlers up by it.
 *
 * Properties this sets on script elements (plain properties, so the
 * minifier leaves their names alone):
 * - _xs: one of our clones of a delayed script;
 * - _xe: on the page, and not delayed, when the replay started;
 * - _xf: written into the page by our document.write redirect;
 * - _xw: where the next write from that script goes.
 */
(function (timeout) {
  var TRIGGERS = ['mousemove', 'keydown', 'touchstart', 'scroll', 'wheel'];
  var PRIVATE = {
    DOMContentLoaded: 'xs-DOMContentLoaded',
    load: 'xs-load',
    readystatechange: 'xs-readystatechange',
  };

  // The real readyState, read past any getter defined on document itself
  // (ours during the replay, or one the page defined).
  var nativeReadyState = Object.getOwnPropertyDescriptor(Document.prototype, 'readyState');
  function realReadyState() {
    return nativeReadyState ? nativeReadyState.get.call(document) : document.readyState;
  }

  // readyState cannot say whether DOMContentLoaded has fired: it turns
  // 'interactive' BEFORE the defer scripts and modules run. This runs
  // inline in the footer, before DOMContentLoaded, so a listener records
  // the real event; Navigation Timing covers a bootstrap that ran later.
  // The same for load.
  var started = false;
  var dclFired = realReadyState() === 'complete';
  var loadFired = dclFired;
  var onRealDcl;
  document.addEventListener('DOMContentLoaded', function () {
    dclFired = true;
    if (onRealDcl) onRealDcl();
  });
  window.addEventListener('load', function () {
    loadFired = true;
  });

  // Dispatch the private copy of a lifecycle event under its real type.
  // The dispatched load reports document as its target, as the real one
  // does.
  function firePrivate(target, type, bubbles) {
    var event = new Event(PRIVATE[type], { bubbles: !!bubbles });
    Object.defineProperty(event, 'type', { value: type });
    if (type === 'load') Object.defineProperty(event, 'target', { value: document });
    target.dispatchEvent(event);
  }

  function callHandler(handler, target, type) {
    if (typeof handler !== 'function') return;
    try {
      handler.call(target, new Event(type));
    } catch (e) {}
  }

  function typeOf(script) {
    return (script.getAttribute('type') || '').trim().toLowerCase();
  }

  // Whether the browser will execute a script of this type. One it will
  // not run (text/plain, nomodule, text/babel) fires neither load nor
  // error, so counting it would hold the replay open.
  function willRun(script, type) {
    return !script.noModule && /^$|^module$|^(text|application)\/(x-)?(java|ecma|j|live)script$/.test(type);
  }

  function start() {
    if (started) return;
    started = true;
    TRIGGERS.forEach(function (name) {
      window.removeEventListener(name, start, { passive: true, capture: true });
    });
    var nav = window.performance && performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
    if (nav && nav.domContentLoadedEventStart > 0) dclFired = true;

    // pageLoaded: the replay starts after load, the usual case ("late").
    // phase: 0 until the synthetic DOMContentLoaded, then 1 until load.
    // pending: what the current phase still waits for; it starts at 1 for
    // the loop below. heldInlines: inline scripts still waiting behind an
    // external. modules: inline module index -> 1 inserted, 2 running
    // between its markers, 0 done.
    var pageLoaded = loadFired;
    var live = 1;
    var wrapped = [];
    var pending = 1;
    var deadline;
    var phase = 0;
    var heldInlines = 0;
    var modules = {};

    // When the replay starts before the real DOMContentLoaded, wait for it
    // before sending our own copy, so a script that registered after it
    // still gets one.
    if (!dclFired) {
      pending++;
      onRealDcl = function () {
        onRealDcl = 0;
        Promise.resolve().then(function () {
          if (!phase) done();
        });
      };
    }

    // A readystatechange 'complete' still to come is forwarded to the
    // private name when it happens. This is registered before our wrappers,
    // so it goes on the real name. A handler a delayed script set on
    // document.onreadystatechange before the real 'interactive' hears it
    // from the browser, so the synthetic one skips it.
    if (!pageLoaded) {
      document.addEventListener('readystatechange', function () {
        if (realReadyState() === 'interactive') currentRsc = document.onreadystatechange;
        if (realReadyState() === 'complete') firePrivate(document, 'readystatechange');
      });
    }

    // Scripts already on the page keep the real event names and the native
    // document.write for their own top-level code: a page defer script's
    // readystatechange listener hears only the real one, and the browser
    // ignores their writes after parsing as it always did.
    Array.prototype.forEach.call(document.scripts, function (script) {
      if (!script.hasAttribute('data-xs-delay')) script._xe = 1;
    });

    // The wrappers. What is renamed is decided on each call, by which events
    // have really fired: DOMContentLoaded once it has, readystatechange once
    // parsing has finished, window load once the page has loaded. A listener
    // for an event still to come stays on the real name and hears the real
    // event, whoever adds it; one for an event already gone gets a private
    // copy. Renaming everything from the start was worse: eager scripts
    // still registering had their listeners renamed, jQuery's completed()
    // ran twice, and the Interactivity API's hydration was held until the
    // replay ended.
    //
    // load is renamed on window only. The page's load never reaches a
    // listener on document, so one there catches its descendants' loads
    // in the capture phase (image delegation); renaming it would cut the
    // delayed script off from every image after this.
    //
    // What renaming cannot tell apart: eager code in a timer or handler (no
    // currentScript) registering during the replay gets one synthetic event
    // where it would have got none.
    //
    // The wrappers look the prototype method up on every call: Sentry or
    // zone.js, delayed too, can patch EventTarget.prototype during the
    // replay, and listeners added after that must go through their patch.
    // A removal takes the listener off both the real and the private name,
    // so one added before an event fired and removed after (jQuery's
    // completed()) is still found.
    [document, window].forEach(function (target) {
      var proto = Object.getPrototypeOf(target);
      var ownAdd = target.addEventListener;
      var ownRemove = target.removeEventListener;
      var hadOwn = Object.prototype.hasOwnProperty.call(target, 'addEventListener');
      var nameFor = function (type) {
        var current = document.currentScript;
        var gone =
          type === 'load'
            ? target === window && loadFired
            : type === 'DOMContentLoaded'
              ? dclFired
              : realReadyState() !== 'loading';
        return live && !(current && current._xe) && PRIVATE.hasOwnProperty(type) && gone ? PRIVATE[type] : type;
      };
      var add = function (type, listener, options) {
        return (hadOwn ? ownAdd : proto.addEventListener).call(this, nameFor(type), listener, options);
      };
      target.addEventListener = add;
      target.removeEventListener = function (type, listener, options) {
        var remove = hadOwn ? ownRemove : proto.removeEventListener;
        if (PRIVATE.hasOwnProperty(type)) remove.call(this, PRIVATE[type], listener, options);
        return remove.call(this, type, listener, options);
      };
      wrapped.push([target, ownAdd, ownRemove, hadOwn, add]);
    });

    // window.onload / document.onreadystatechange set during the replay are
    // keyed on the real names, so they are called directly. A page handler
    // already there is swapped for an empty one first: the old addLoadEvent
    // chain (`var o=window.onload; window.onload=function(){o();mine();}`)
    // would otherwise run it a second time. It has run, and the browser
    // will not call it again. If nothing replaced the empty one, the page's
    // handler is put back at the end, so code that calls window.onload()
    // later (a PJAX re-init) still finds it.
    var pageOnload = window.onload;
    var pageRsc = document.onreadystatechange;
    var currentOnload = pageOnload;
    var currentRsc = pageRsc;
    var jq = window.jQuery;
    var ended;
    if (pageLoaded && pageOnload) currentOnload = window.onload = function () {};
    if (pageLoaded && pageRsc) currentRsc = document.onreadystatechange = function () {};

    // The replayed scripts read a fake document.readyState: 'loading' while
    // they run, then 'interactive' for the synthetic DOMContentLoaded, then
    // the real value once the replay ends. Left at the real value, a script
    // that starts at once when the page is past 'loading' and also adds an
    // unguarded DOMContentLoaded listener started twice, and a
    // readystatechange handler waiting for 'interactive' never ran.
    //
    // The fake is scoped: the getter answers with it only while one of our
    // clones runs (currentScript carries _xs), while an inline module runs
    // between its markers (modules have no currentScript), and while our own
    // dispatch runs (fakeDepth). Everything else reads the real value: eager
    // code, timers, callbacks, and scripts the page or the replayed ones
    // inject. So a delayed script that listens at top level and re-checks
    // for 'complete' in a timer starts twice.
    //
    // The define is configurable and in a try: WP Rocket's first fake threw
    // where Cloudflare Rocket Loader had locked the property (#5709), and a
    // locked readyState keeps the old behaviour here. A getter the page
    // defined itself (another optimizer, a polyfill) is read through and
    // put back, not deleted.
    var writtenPending = 0;
    var fakeState = 'loading';
    var fakeDepth = 0;
    var ownReadyState = Object.getOwnPropertyDescriptor(document, 'readyState');
    var faking =
      nativeReadyState &&
      (function () {
        try {
          Object.defineProperty(document, 'readyState', {
            configurable: true,
            get: function () {
              var current = document.currentScript;
              if ((current && current._xs) || fakeDepth) return fakeState;
              return ownReadyState && ownReadyState.get ? ownReadyState.get.call(document) : realReadyState();
            },
          });
          return 1;
        } catch (e) {}
      })();

    // With readyState faked, a script may write into the page as if it were
    // still being parsed. From an inline script that would wipe the
    // document, so for the length of the replay document.write/writeln put
    // the markup in after the script that wrote it, in call order. A script
    // the parser is still running writes into the parser as always: while
    // the real readyState is 'loading', a caller that is not one of our
    // clones gets the native write. With no currentScript (a timer, a
    // callback) there is nowhere to put it, and the write is dropped rather
    // than wiping the page.
    //
    // A written <script src> runs whenever it arrives: fragment scripts are
    // async. One written that way (an ad tag's second stage) writes through
    // the redirect too, even while the page still parses, and the redirect
    // stays until every written <script src> has loaded or failed, however
    // long after the replay that is. Each of write and writeln is put back
    // only if it is still ours: an ad loader may have installed its own.
    var nativeWrite = [
      document.write,
      document.writeln,
      Object.prototype.hasOwnProperty.call(document, 'write'),
    ];
    function redirectWrite(args, end) {
      var current = document.currentScript;
      var html = Array.prototype.join.call(args, '') + end;
      if (!current) return;
      if (current._xe || (!current._xs && !current._xf && realReadyState() === 'loading')) {
        return (end ? nativeWrite[1] : nativeWrite[0]).apply(document, args);
      }
      if (!current.parentNode) return;
      if (!('_xw' in current)) current._xw = current.nextSibling;
      try {
        var fragment = document.createRange().createContextualFragment(html);
        Array.prototype.forEach.call(fragment.querySelectorAll('script'), function (script) {
          script._xf = 1;
          if (!script.src) return;
          writtenPending++;
          var settled = 0;
          var settle = function () {
            if (settled) return;
            settled = 1;
            writtenPending--;
            if (ended && !heldInlines && !writtenPending) restoreWrite();
          };
          script.addEventListener('load', settle);
          script.addEventListener('error', settle);
        });
        current.parentNode.insertBefore(fragment, current._xw);
      } catch (e) {}
    }
    var ourWrite = (document.write = function () {
      redirectWrite(arguments, '');
    });
    var ourWriteln = (document.writeln = function () {
      redirectWrite(arguments, '\n');
    });
    function restoreWrite() {
      if (document.write === ourWrite) {
        if (nativeWrite[2]) document.write = nativeWrite[0];
        else delete document.write;
      }
      if (document.writeln === ourWriteln) {
        if (nativeWrite[2]) document.writeln = nativeWrite[1];
        else delete document.writeln;
      }
    }

    function fireReadyStateChange() {
      firePrivate(document, 'readystatechange');
      if (document.onreadystatechange !== currentRsc) {
        callHandler(document.onreadystatechange, document, 'readystatechange');
      }
    }

    // jQuery keeps ONE native listener per element and type. If window load
    // already had jQuery handlers before the replay, that listener is on the
    // real name and the private dispatch cannot reach handlers appended to
    // the list, so those, and only those, are called directly in finish().
    function jqueryLoadHandlers() {
      var events = jq && jq._data && jq._data(window, 'events');
      return (events && events.load) || [];
    }
    var jqueryLoadCount = jqueryLoadHandlers().length;

    // Called once per thing the current phase waits for. Order matches a
    // real page: readystatechange ('interactive'), DOMContentLoaded
    // (bubbles document -> window, so it is not also dispatched on window),
    // readystatechange ('complete'), load.
    //
    // Between DOMContentLoaded and load there is a second wait. On a real
    // page a script inserted before load delays it, so a script that a
    // DOMContentLoaded handler or jQuery ready code injects (GTM's DOM Ready
    // trigger) always hears load. So the observer stays on, and load waits
    // for what it sees. A wait left over from the first phase (the deadline
    // cut it short) is ignored when it ends, and so are module markers.
    //
    // load also waits for jQuery's ready callbacks. On a real page ready
    // runs before load, so `jQuery(function(){ $(window).on('load', f) })`,
    // the common WordPress pattern, gets f. jQuery 3 runs ready callbacks on
    // timers, so a callback queued after the delayed scripts' own, then one
    // more timer, is when they have all run. 1s caps it (jQuery.holdReady
    // can hold ready indefinitely); the callback and the cap share one slot.
    function done() {
      if (pending < 1 || --pending) return;
      if (phase) return finish();
      phase = 1;
      clearTimeout(deadline);
      for (var i in modules) if (modules[i] === 2) fakeDepth--;
      modules = {};
      if (faking) {
        fakeState = 'interactive';
        fakeDepth++;
        fireReadyStateChange();
      }
      firePrivate(document, 'DOMContentLoaded', 1);
      if (faking) fakeDepth--;
      if (pageLoaded && !faking) fireReadyStateChange();
      pending = 1;
      var $ = window.jQuery;
      var once = 0;
      var ready = function () {
        if (!once) {
          once = 1;
          done();
        }
      };
      try {
        if ($ && $.fn && $.fn.ready) {
          $(function () {
            setTimeout(ready, 0);
          });
          setTimeout(ready, 1000);
          return;
        }
      } catch (e) {}
      setTimeout(ready, 0);
    }

    // Restore puts back what was there (delete our own-property shadow, or
    // reassign a shadow someone set before us) unless another wrapper has
    // since been put on top; `live` makes ours inert either way.
    function finish() {
      if (ended) return;
      ended = 1;
      if (observer) observer.disconnect();
      if (faking) {
        if (ownReadyState) Object.defineProperty(document, 'readyState', ownReadyState);
        else delete document.readyState;
        if (pageLoaded) fireReadyStateChange();
      }
      if (!heldInlines && !writtenPending) restoreWrite();
      live = false;
      wrapped.forEach(function (entry) {
        var target = entry[0];
        if (target.addEventListener !== entry[4]) return;
        if (entry[3]) {
          target.addEventListener = entry[1];
          target.removeEventListener = entry[2];
        } else {
          delete target.addEventListener;
          delete target.removeEventListener;
        }
      });
      if (loadFired) firePrivate(window, 'load');
      if (pageLoaded) {
        if (window.onload !== currentOnload) callHandler(window.onload, window, 'load');
        if (jqueryLoadCount) {
          jqueryLoadHandlers()
            .slice(jqueryLoadCount)
            .forEach(function (handleObj) {
              try {
                var event = jq.Event('load');
                event.currentTarget = window;
                event.handleObj = handleObj;
                event.data = handleObj.data;
                handleObj.handler.call(window, event);
              } catch (e) {}
            });
        }
      }
      if (pageOnload && window.onload === currentOnload) window.onload = pageOnload;
      if (pageLoaded && pageRsc && document.onreadystatechange === currentRsc) document.onreadystatechange = pageRsc;
      // Marks the end, for tests and integrators.
      document.dispatchEvent(new Event('xspeed:replayed'));
    }

    // Wait for a script's load or error: listeners, never n.onload, which
    // would replace an author's copied onload attribute. They go on before
    // insertion, which is what starts the fetch.
    function wait(script, cap) {
      pending++;
      var settled = 0;
      var timer;
      var inPhase = phase;
      function one() {
        if (settled) return;
        settled = 1;
        clearTimeout(timer);
        if (inPhase === phase) done();
      }
      script.addEventListener('load', one);
      script.addEventListener('error', one);
      if (cap) timer = setTimeout(one, cap);
    }

    // A script a replayed script injects (a tag manager's payload, a widget's
    // real code) is waited for too, 1s each: the cap WP Rocket and WP Meteor
    // use, because some never fire load or error (one added through
    // innerHTML never runs). A script injected later than that gets no
    // events. Our own clones carry _xs, so they are not counted twice.
    function seen(script) {
      if (!script._xs && script.hasAttribute('src') && willRun(script, typeOf(script))) wait(script, 1000);
    }
    var observer =
      window.MutationObserver &&
      new MutationObserver(function (records) {
        records.forEach(function (record) {
          Array.prototype.forEach.call(record.addedNodes, function (node) {
            if (node.nodeName === 'SCRIPT') seen(node);
            else if (node.querySelectorAll) Array.prototype.forEach.call(node.querySelectorAll('script'), seen);
          });
        });
      });
    if (observer) observer.observe(document.documentElement, { childList: true, subtree: true });

    // A server that never answers does not hold the events past 15s.
    deadline = setTimeout(function () {
      pending = 1;
      done();
    }, 15000);

    // An inline module evaluates asynchronously but fires no load (the spec
    // fires it only for scripts from a URL), so its replayed text starts and
    // ends with a line that dispatches xs-mod with its index, and the end is
    // counted in pending. Its imports are hoisted, so the end runs once the
    // module and its whole import graph have run. An import that fails to
    // load fires error on the element, which also counts.
    //
    // A module that throws never reaches its end line, but its start line
    // ran and the throw is reported to window: an error while a module is
    // between its markers releases that module only. The release runs one
    // microtask after the error: an error thrown by another listener while
    // the module is still running is reported synchronously, and by the
    // microtask that module has reached its end line. A module paused at a
    // top-level await is between its markers too, so an unrelated error
    // during the pause releases it early; a precise rule costs more than it
    // saves. A dependency that throws stops the module before its start
    // line; the deadline covers that. A module's end is counted a microtask
    // later, so a script it injects is seen first.
    function moduleDone(index) {
      if (modules[index]) {
        if (modules[index] === 2) fakeDepth--;
        modules[index] = 0;
        Promise.resolve().then(done);
      }
    }
    document.addEventListener('xs-mod', function (event) {
      var detail = event.detail;
      if (detail > 0) moduleDone(detail);
      else if (modules[-detail] === 1) {
        modules[-detail] = 2;
        fakeDepth++;
      }
    });
    window.addEventListener('error', function () {
      Promise.resolve().then(function () {
        for (var i in modules) if (modules[i] === 2) moduleDone(i);
      });
    });

    // Build the executable copy of a parked tag. index is its 1-based place
    // in the loop, or 0 for a copy nothing waits for.
    function clone(parked, index) {
      var script = document.createElement('script');
      script._xs = 1;
      // A dynamically-created script is async by default, so replayed
      // externals would race each other; async=false restores document
      // order among them.
      script.async = false;
      // Nonce hiding: a connected element's nonce content attribute reads as
      // "", so copying it through the attribute loop would hand the clone an
      // empty nonce and a nonce CSP would block it. The IDL property still
      // carries the real value.
      if (parked.nonce) script.nonce = parked.nonce;
      Array.prototype.slice.call(parked.attributes).forEach(function (attr) {
        if (attr.name === 'data-xs-src') {
          script.setAttribute('src', attr.value);
          return;
        }
        if (attr.name === 'data-xs-delay') return;
        if (attr.name === 'nonce') return;
        // A parked inline tag's original type (module, mostly) rides in
        // data-xs-type: restore it, or a module runs as a classic script and
        // its imports throw (#274).
        if (attr.name === 'data-xs-type') {
          script.setAttribute('type', attr.value);
          return;
        }
        // type is what a script IS, so it is carried over, except our own
        // parking marker, which exists only to stop the browser running the
        // original. Dropping type wholesale made type="module" a classic
        // script and made a consent manager's type="text/plain" executable
        // again, which is a privacy failure (#274).
        if (attr.name === 'type' && attr.value === 'text/xspeed-delayed') return;
        script.setAttribute(attr.name, attr.value);
      });
      if (!parked.hasAttribute('data-xs-src')) script.text = parked.text;
      var type = typeOf(script);
      if (index && willRun(script, type)) {
        if (script.hasAttribute('src')) wait(script);
        else if (type === 'module') {
          pending++;
          modules[index] = 1;
          script.text =
            "document.dispatchEvent(new CustomEvent('xs-mod',{detail:-" +
            index +
            '}));' +
            script.text +
            "\n;document.dispatchEvent(new CustomEvent('xs-mod',{detail:" +
            index +
            '}))';
          script.addEventListener('error', function () {
            moduleDone(index);
          });
          // A CSP that lists inline scripts by hash blocks the changed text,
          // so on an enforced violation the untouched original goes in
          // instead, unwaited for. Report-only violations are ignored: that
          // copy ran.
          script.addEventListener('securitypolicyviolation', function (event) {
            if (event.disposition === 'enforce' && event.blockedURI === 'inline' && script.parentNode) {
              script.parentNode.replaceChild(clone(parked), script);
              moduleDone(index);
            }
          });
        }
      }
      return script;
    }

    // The loop. An inline script runs the moment it is inserted, so one
    // after a delayed external would run before that external arrived
    // (`jQuery(function(){...})` after a delayed jQuery threw). An inline
    // that follows a classic, non-async, non-defer executable external is
    // inserted from that external's load/error listener instead. The
    // async=false externals run from one ordered list, and the spec fires
    // each one's load right after it runs and before the next starts, so the
    // inline runs exactly between them; the externals are still all inserted
    // at once, so they download in parallel. An async or defer external and
    // a module hold nothing: on a real page none of them blocks the inline
    // after it.
    //
    // A held inline's text is unchanged, so a hash CSP still allows it. Its
    // hold is released a microtask after it goes in, so a script it injects
    // is counted first. A hold released after the deadline no longer counts
    // toward any wait, but the write redirect stays until the last one has
    // gone in. Each tag is cloned and inserted inside its own try, so one
    // the browser refuses costs that script only.
    //
    // A parked tag an earlier replayed script took out of the document is
    // skipped: with no parent, replaceChild throws, and inside a detached
    // subtree the clone never loads. contains(), not isConnected, which
    // older engines lack.
    var lastBlocking;
    document.querySelectorAll('script[data-xs-delay]').forEach(function (parked, k) {
      if (!document.documentElement.contains(parked)) return;
      if (lastBlocking && !parked.hasAttribute('data-xs-src')) {
        pending++;
        heldInlines++;
        var released = 0;
        var inPhase = phase;
        var release = function () {
          if (released) return;
          released = 1;
          heldInlines--;
          try {
            if (document.documentElement.contains(parked)) {
              parked.parentNode.replaceChild(clone(parked, inPhase === phase ? k + 1 : 0), parked);
            }
          } catch (e) {}
          if (ended && !heldInlines && !writtenPending) restoreWrite();
          if (inPhase === phase) Promise.resolve().then(done);
        };
        lastBlocking.addEventListener('load', release);
        lastBlocking.addEventListener('error', release);
        return;
      }
      try {
        var script = clone(parked, k + 1);
        parked.parentNode.replaceChild(script, parked);
      } catch (e) {
        return;
      }
      var type = typeOf(script);
      if (
        script.hasAttribute('src') &&
        type !== 'module' &&
        !script.async &&
        !script.hasAttribute('defer') &&
        willRun(script, type)
      ) {
        lastBlocking = script;
      }
    });
    // An inline loader (GTM, gtag, the Meta pixel) injects during the loop,
    // and the observer's records for it arrive in the microtask queued
    // before this one.
    Promise.resolve().then(done);
  }

  TRIGGERS.forEach(function (name) {
    window.addEventListener(name, start, { passive: true, capture: true });
  });
  // The failsafe timer, for visitors who never interact. 0 means
  // interaction only.
  if (timeout > 0) setTimeout(start, timeout);
})(XSPEED_DELAY_TIMEOUT);
