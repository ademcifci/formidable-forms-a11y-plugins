/* global jQuery */
(function ($) {
  'use strict';

  function safeFocus($el) {
    if (!$el || !$el.length) return;
    var added = false;
    if (!$el.is('[tabindex]')) { $el.attr('tabindex', '-1'); added = true; }
    $el.trigger('focus');
    if (added || $el.attr('tabindex') === '-1') {
      if (typeof trackTabindex === 'function') try { trackTabindex($el, 'safeFocus'); } catch (e) {}
    }
  }

  // Read settings localized from PHP with sensible defaults
  var CFG = window.ff_globa11y || {
    other_fields_fix: true,
    global_message_focus: false,
    success_message_focus: true,
    multi_page_focus: true,
    multi_page_focus_level: 1,
    has_accessible_errors: false,
    remove_alert_role_on_field_errors: false,
    remove_choice_error_alert_role: false,
    loading_focus_anchor: false,
    sync_page_title: false,
    debug_tabindex: false,
    formidable_version: ''
  };

  function isFormidable7OrNewer() {
    var v = CFG.formidable_version || '';
    var parts = String(v).split('.');
    var major = parseInt(parts[0], 10);
    return !isNaN(major) && major >= 7;
  }
  CFG.has_accessible_errors = !!CFG.has_accessible_errors;
  try {
    if (!CFG.debug_tabindex) {
      var qs = (window.location && window.location.search) || '';
      if (/([?&])ff_globa11y_debug=1(?!\d)/.test(qs)) CFG.debug_tabindex = true;
    }
  } catch (e) {}

  function trackTabindex($el, label) {
    if (!CFG.debug_tabindex || !$el || !$el.length || typeof MutationObserver === 'undefined') return;
    var el = $el.get(0);
    try {
      if (el.__ffTabindexObserver) {
        try { el.__ffTabindexObserver.disconnect(); } catch (e) {}
      }
      var observer = new MutationObserver(function () {
        var current = el.getAttribute('tabindex');
        if (current !== '-1') {
          // eslint-disable-next-line no-console
          console.warn('[ff_globa11y] tabindex changed/removed', { label: label || '', element: el, tabindex: current });
          try { observer.disconnect(); } catch (e) {}
        }
      });
      observer.observe(el, { attributes: true, attributeFilter: ['tabindex'] });
      el.__ffTabindexObserver = observer;
    } catch (e) {}
  }

  // Intent tracking for multi-page focus (covers no-sessionStorage cases)
  var mpIntent = false;
  function setMpIntent() {
    mpIntent = true;
    try { if (window.sessionStorage) sessionStorage.setItem('fga11y_mp_focus', '1'); } catch (e) {}
  }
  function consumeMpIntent() {
    var had = !!mpIntent;
    mpIntent = false;
    var hadSess = false;
    try {
      if (window.sessionStorage && sessionStorage.getItem('fga11y_mp_focus') === '1') {
        hadSess = true;
        sessionStorage.removeItem('fga11y_mp_focus');
      }
    } catch (e) {}
    return had || hadSess;
  }

  // 1) "Other" inputs: hide duplicate SR labels, move text into aria-label
  function enhanceOtherInputs(context) {
    var $ctx = context ? $(context) : $(document);
    $ctx.find('.frm_other_input').each(function () {
      var $input = $(this);
      var $srLabel = $input.siblings('label.frm_screen_reader').first();
      if ($srLabel.length) {
        $srLabel.css('display', 'none');
        var txt = ($srLabel.text() || '').trim();
        if (txt) $input.attr('aria-label', txt);
      }
    });
  }

  // Remove role=alert from inline field errors when site opts out via filter
  function adjustInlineErrorRoles(context) {
    var REMOVE_ALERT = !!(CFG.remove_alert_role_on_field_errors || CFG.remove_choice_error_alert_role);
    if (!REMOVE_ALERT) return;
    var $ctx = context ? $(context) : $(document);
    $ctx.find('.frm_error_style[role="alert"], .frm_error[role="alert"], .frm_inline_error[role="alert"], [data-frm-error][role="alert"]').each(function () {
      var $err = $(this);
      if ($err.attr('role') === 'alert') {
        $err.removeAttr('role');
      }
    });
  }

  // 2) Focus management for global errors and success messages
  function focusMessages(context) {
    var $ctx = context ? $(context) : $(document);

    // If Accessible Errors is active, skip error focusing but still handle success.
    if (!CFG.has_accessible_errors && CFG.global_message_focus) {
      // Prefer accessible error summary if present
      var $ffSummary = $ctx.find('.ff-global-errors').first();
      if ($ffSummary.length) {
        var $head = $ffSummary.find('[role="heading"], h2, h3').first();
        if ($head.length) {
          $head.attr('tabindex', '-1');
          try { trackTabindex($head, 'ff-global-errors heading'); } catch (e) {}
          safeFocus($head);
          return;
        }
        $ffSummary.attr('tabindex', '-1');
        try { trackTabindex($ffSummary, 'ff-global-errors container'); } catch (e) {}
        safeFocus($ffSummary);
        return;
      }

      // Legacy/global errors fallback
      var $errorHeading = $ctx.find('.global-errors h2').first();
      if ($errorHeading.length) {
        $errorHeading.attr('tabindex', '-1');
        try { trackTabindex($errorHeading, 'legacy global-errors h2'); } catch (e) {}
        safeFocus($errorHeading);
        return;
      }

      // Formidable 7.0 core summary (when Accessible Errors is not replacing markup).
      if (isFormidable7OrNewer()) {
        var $coreSummary = $ctx.find('[data-frm-error-summary]').first();
        if ($coreSummary.length && !$ctx.find('[data-ff-error-summary]').length) {
          $coreSummary.attr('tabindex', '-1');
          try { trackTabindex($coreSummary, 'frm_error_style data-frm-error-summary'); } catch (e) {}
          safeFocus($coreSummary);
          return;
        }
      }
    }

    // Success messages - defer to Accessible Error Summary when active.
    if (CFG.has_accessible_errors) {
      return;
    }
    if (!CFG.success_message_focus) return;
    var $msg = $ctx.find('.frm_message').first();
    if ($msg.length) {
      $msg.attr('tabindex', '-1');
      try { trackTabindex($msg, 'frm_message'); } catch (e) {}
      safeFocus($msg);
      return;
    }
    return;
  }

  var supportsWeakSet = typeof WeakSet !== 'undefined';
  var processedMessages = supportsWeakSet ? new WeakSet() : [];

  function hasSeenMessage(node) {
    if (!node) {
      return false;
    }
    if (supportsWeakSet) {
      return processedMessages.has(node);
    }
    for (var i = 0; i < processedMessages.length; i++) {
      if (processedMessages[i] === node) {
        return true;
      }
    }
    return false;
  }

  function rememberMessage(node) {
    if (!node) {
      return;
    }
    if (supportsWeakSet) {
      processedMessages.add(node);
      return;
    }
    processedMessages.push(node);
  }

  function observeSuccessMessages() {
    if (typeof MutationObserver === 'undefined') {
      return;
    }
    try {
      var observer = new MutationObserver(function (mutations) {
        if (!mutations || !mutations.length) {
          return;
        }
        for (var i = 0; i < mutations.length; i++) {
          var mutation = mutations[i];
          if (!mutation.addedNodes || !mutation.addedNodes.length) {
            continue;
          }
          for (var j = 0; j < mutation.addedNodes.length; j++) {
            var node = mutation.addedNodes[j];
            if (!node || node.nodeType !== 1) {
              continue;
            }
            var target = null;
            if (node.classList && node.classList.contains('frm_message')) {
              target = node;
            } else if (node.querySelector) {
              target = node.querySelector('.frm_message');
            }
            if (target && !hasSeenMessage(target)) {
              rememberMessage(target);
              focusMessages(target);
            }
          }
        }
      });
      observer.observe(document.documentElement || document.body, { childList: true, subtree: true });
    } catch (e) {}
  }

  // 3) Multi-page: focus H1 in the current page/form after navigation
  function focusFirstHeadingIn($root) {
    var level = parseInt(CFG.multi_page_focus_level, 10);
    if (!(level >= 1 && level <= 6)) level = 1;
    var selector = 'h' + level + ':visible';
    var $h = $root && $root.length ? $root.find(selector).first() : $();
    if (!$h || !$h.length) {
      $h = $(selector).first();
    }
    if ($h.length) {
      safeFocus($h);
      return true;
    }
    return false;
  }

  function runEnhancements(context) {
    var ctx = context || document;
    if (CFG.other_fields_fix) {
      enhanceOtherInputs(ctx);
    }
    adjustInlineErrorRoles(ctx);
    focusMessages(ctx);
  }

  function attemptFocusH1Around($el, tries, delay) {
    tries = typeof tries === 'number' ? tries : 10;
    delay = typeof delay === 'number' ? delay : 120;
    var $root = $el && $el.length ? $el.closest('form, .frm-show-form, .frm_form_fields') : $(document);
    // Prefer errors over heading after multi-page navigation
    var $foundSummary = $root.find('.ff-global-errors, .global-errors h2').first();
    if (!$foundSummary.length) {
      $foundSummary = $(document).find('.ff-global-errors, .global-errors h2').first();
    }
    if ($foundSummary.length) {
      if (CFG.has_accessible_errors) {
        // Accessible Errors will handle focusing the summary; avoid overriding
        return;
      }
      // Otherwise, use our own message focusing
      focusMessages($foundSummary.closest('form, .frm-show-form, .frm_form_fields').length ? $foundSummary.closest('form, .frm-show-form, .frm_form_fields') : document);
      return;
    }
    var done = focusFirstHeadingIn($root);
    if (done) return;
    if (tries <= 0) return;
    setTimeout(function () { attemptFocusH1Around($el, tries - 1, delay); }, delay);
  }

  var focusAnchorNode = null;
  var originalTitle = document.title || '';
  var loadingMessage = 'Loading, please wait.';

  function ensureFocusAnchor() {
    if (focusAnchorNode && document.body.contains(focusAnchorNode)) {
      return focusAnchorNode;
    }
    var existing = document.getElementById('ff-globa11y-focus-anchor');
    if (existing) {
      focusAnchorNode = existing;
      return focusAnchorNode;
    }
    var anchor = document.createElement('div');
    anchor.id = 'ff-globa11y-focus-anchor';
    anchor.setAttribute('tabindex', '-1');
    anchor.setAttribute('role', 'status');
    anchor.setAttribute('aria-live', 'polite');
    anchor.style.position = 'absolute';
    anchor.style.padding = '0';
    anchor.style.margin = '-1px';
    anchor.style.width = '1px';
    anchor.style.height = '1px';
    anchor.style.overflow = 'hidden';
    anchor.style.clip = 'rect(0 0 0 0)';
    anchor.style.borderWidth = '0';
    anchor.style.whiteSpace = 'nowrap';

    if (document.body && document.body.firstChild) {
      document.body.insertBefore(anchor, document.body.firstChild);
    } else if (document.body) {
      document.body.appendChild(anchor);
    }
    focusAnchorNode = anchor;
    return focusAnchorNode;
  }

  function clearFocusAnchorMessage() {
    if (focusAnchorNode && focusAnchorNode.textContent) {
      focusAnchorNode.textContent = '';
    }
  }

  function handleLoadingFocus() {
    if (!CFG.loading_focus_anchor) {
      return;
    }
    var anchor = ensureFocusAnchor();
    if (!anchor) {
      return;
    }
    anchor.textContent = loadingMessage;
    try {
      anchor.focus();
    } catch (e) {}
  }

  function initLoadingFocusAnchor() {
    if (!CFG.loading_focus_anchor) {
      return;
    }
    ensureFocusAnchor();
    var selectors = '.frm_ajax_submit .frm_prev_page, .frm_ajax_submit .frm_next_page, .frm_ajax_submit .frm_submit, button.frm-abandonment-modal-btn.frm-abandonment-modal-btn-primary';
    $(document).on('click', selectors, handleLoadingFocus);
    $(document).on('frmFormComplete frmPageChanged', clearFocusAnchorMessage);
  }

  function normalizeHeadingLevel(level) {
    var parsed = parseInt(level, 10);
    if (!(parsed >= 1 && parsed <= 6)) {
      parsed = 1;
    }
    return parsed;
  }

  function updateTitleFromHeading() {
    if (!CFG.sync_page_title) {
      return;
    }
    var level = normalizeHeadingLevel(CFG.multi_page_focus_level);
    var selector = 'h' + level;
    var heading = document.querySelector(selector);
    if (!heading || !heading.textContent) {
      document.title = originalTitle;
      clearFocusAnchorMessage();
      return;
    }
    var text = heading.textContent.trim();
    if (text) {
      document.title = text + ' \u2013 ' + originalTitle;
    } else {
      document.title = originalTitle;
    }
    clearFocusAnchorMessage();
  }

  function initTitleSync() {
    if (!CFG.sync_page_title) {
      return;
    }
    updateTitleFromHeading();
    $(document).on('frmFormComplete frmPageChanged', updateTitleFromHeading);
  }

  $(function () {
    // Track last submitter in case submit happens without a click event
    var lastSubmitter = null;
    observeSuccessMessages();
    initLoadingFocusAnchor();
    initTitleSync();
    if (CFG.multi_page_focus) {
      $(document).on('click keydown', '.frm_next_page, .frm_prev_page', function () {
        lastSubmitter = this;
        setMpIntent();
      });
      // Set flag on submit if the submitter is Next/Prev (handles full reload flows)
      $(document).on('submit', 'form', function (e) {
        var submitter = (e.originalEvent && e.originalEvent.submitter) ? e.originalEvent.submitter : lastSubmitter;
        if (submitter && ($(submitter).is('.frm_next_page, .frm_prev_page'))) {
          setMpIntent();
        }
      });
    }
    runEnhancements(document);
    

    // Multi-page: handle following scenarios
    if (CFG.multi_page_focus) {
      // a) If we previously navigated pages via full reload, focus now
      try {
        if (consumeMpIntent()) {
          attemptFocusH1Around($(document));
        }
      } catch (e) {}

      // b) When clicking next/prev page buttons (AJAX transitions)
      // Set the intent flag but do not pre-focus; wait for content change.
      $(document).on('click', '.frm_next_page, .frm_prev_page', function () {
        setMpIntent();
      });
    }

    $(document).on('ajaxComplete', function () {
      // Scope to Formidable-related AJAX requests only
      var args = arguments;
      try {
        var settings = args && args.length >= 3 ? args[2] : null;
        var url = settings && settings.url ? String(settings.url) : '';
        var dataStr = settings && settings.data != null ? String(settings.data) : '';
        var isFormidableAjax = /admin-ajax\.php|formidable|\bfrm\b|frm_|frm\-/.test(url) || /formidable|\bfrm\b|frm_|frm\-/.test(dataStr);
        if (!isFormidableAjax) {
          return; // ignore unrelated AJAX completions
        }
      } catch (e) {}
      runEnhancements(document);
      // Focus H1 only if a Next/Prev navigation was initiated (session flag)
      if (CFG.multi_page_focus) {
        if (consumeMpIntent()) {
          attemptFocusH1Around($(document));
          // extra retries in case of delayed renders
          setTimeout(function () { attemptFocusH1Around($(document)); }, 200);
          setTimeout(function () { attemptFocusH1Around($(document)); }, 500);
        }
      }
    });

    setTimeout(function () {
      runEnhancements(document);

      // Non-AJAX multipage: if we land on a page with a visible Prev button, we are not on page 1
      if (CFG.multi_page_focus) {
        if (consumeMpIntent()) {
          attemptFocusH1Around($(document));
          setTimeout(function () { attemptFocusH1Around($(document)); }, 200);
          return;
        }
        var $prevBtn = $('.frm_prev_page:visible').first();
        if ($prevBtn.length) {
          attemptFocusH1Around($prevBtn);
          setTimeout(function () { attemptFocusH1Around($prevBtn); }, 200);
        }
      }
    }, 0);

    // For AJAX-based multipage transitions, rely on Formidable's event when available
    if (CFG.multi_page_focus) {
      $(document).on('frmPageChanged', function (event, form /*, response */) {
        // Prefer handling when an intent exists, but still attempt as a fallback
        if (consumeMpIntent()) {
          attemptFocusH1Around($(form));
          setTimeout(function () { attemptFocusH1Around($(form)); }, 200);
        } else {
          // Fallback in case intent couldn't be stored (no sessionStorage, keyboard submit, etc.)
          attemptFocusH1Around($(form));
        }
      });
    }
  });

  function handleFormidableLoadEvent(event) {
    var target = event && event.target ? event.target : null;
    var ctx = target && target.nodeType === 1 ? target : document;
    runEnhancements(ctx);
  }

  try {
    document.addEventListener('frmProAfterFormFadeIn', handleFormidableLoadEvent, true);
    document.addEventListener('frm_after_start_over', handleFormidableLoadEvent);
  } catch (e) {}
})(jQuery);





