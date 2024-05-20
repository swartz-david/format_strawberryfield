(function ($, Drupal, drupalSettings) {

  Drupal.views.ajaxView.prototype.filterNestedViews = function () {
    // The parent function basically blocks any nesting. We need nesting.
    // To avoid overwriting ajax_views.js, we check if our main dynamic behavior
    // did already run. If so we will say there is no testing and deal with what Drupal should
    // have done since the begining. Only attach to the closest View!... mmmm
    const oncedElements = once.find('data-sbf-views');
    if (oncedElements.length > 0) {
      return true;
    }
    else {
      // Normal Drupal behavior. Or normal Views with ajax might break
      return !this.$view.parents('.view').length;
    }
  };

  Drupal.views.ajaxView.prototype.attachPagerLinkAjax = function (id, link) {
    const $link = $(link);
    // Don't attach to pagers inside nested views.
    if ($link.closest('.view')[0] !== this.$view[0]) {
      return;
    }
    const viewData = {};
    const href = $link.attr('href');
    // Construct an object using the settings defaults and then overriding
    // with data specific to the link.
    $.extend(
      viewData,
      this.settings,
      Drupal.Views.parseQueryString(href),
      // Extract argument data from the URL.
      Drupal.Views.parseViewArgs(href, this.settings.view_base_path),
    );

    const selfSettings = $.extend({}, this.element_settings, {
      submit: viewData,
      base: false,
      element: link,
      httpMethod: 'GET',
    });
    this.pagerAjax = Drupal.ajax(selfSettings);
  };

  function loadViewOnClickEvent(e) {
    // If using the load even we can't relay on the target anymore because
    // it is bound to the document/window.

    let base = e.target.id;
    // Retrieve the path to use for views' ajax.
    let ajaxPath = drupalSettings?.views?.ajax_path ?? '/views/ajax' ;

    // If there are multiple views this might've ended up showing multiple
    // times.
    if (ajaxPath.constructor.toString().indexOf('Array') !== -1) {
      ajaxPath = ajaxPath[0];
    }

    // Check if there are any GET parameters to send to views.
    let queryString = window.location.search || '';
    if (queryString !== '') {
      // Remove the question mark and Drupal path component if any.
      queryString = queryString
        .slice(1)
        .replace(/q=[^&]+&?|&?render=[^&]+/, '');
      if (queryString !== '') {
        // If there is a '?' in ajaxPath, clean URL are on and & should be
        // used to add parameters.
        queryString = (/\?/.test(ajaxPath) ? '&' : '?') + queryString;
      }
    }

    const base_views_settings =  {
      view_name: e.currentTarget.dataset.sbfViewId,
      view_display_id: e.currentTarget.dataset.sbfViewDisplayId,
      //@TODO use the argument separator settings here.
      view_args: e.currentTarget.dataset.sbfViewArguments,
      view_dom_id: e.currentTarget.dataset.sbfViewRenderTarget,
      view_path: window.location.pathname,
    };
    let submit_settings =
      $.extend(
        {},
        base_views_settings,
      );

    const element_settings = {
      url: drupalSettings.path.baseUrl + drupalSettings.path.pathPrefix + 'sbf/views-ajax' + queryString,
      event: 'loadView',
      base: base,
      element: e.currentTarget,
      httpMethod: "GET",
      progress: {
        type: 'throbber'
      },
      submit: submit_settings
    };
    let ajaxObject = new Drupal.ajax(element_settings);
    ajaxObject.execute();
  };

  Drupal.behaviors.sbf_views_ajax_dynamic = {
    attach: function (context, settings) {
      // the data attributes one can use
      // [data-sbf-view-id="machine_name_of_a_view"]
      // [data-sbf-view-display-id="machine_name_of_the_views_display"]
      // [data-sbf-view-arguments="one,two,tree"]
      const elementsToAttach = once('data-sbf-views', '[data-sbf-view-id]', context);
      elementsToAttach.forEach(function (value, index) {
        // Requires an ID.
        if (value?.dataset?.sbfViewId &&
          value?.dataset?.sbfViewDisplayId &&
          value?.dataset?.sbfViewRenderTarget &&
          value?.id
        ) {
          let eventtype = value?.dataset?.sbfViewEvent
          if (typeof(eventtype) == "undefined") {
            eventtype = "click";
          }
          if (eventtype == "click") {
            value.addEventListener(eventtype, loadViewOnClickEvent, {passive: true, once: true});
          }
          else if (eventtype == "load") {
              value.addEventListener('afterload', loadViewOnClickEvent, {passive: true, once: true});
              const event = new Event('afterload');
              value.dispatchEvent(event);
          }
        }
      });
    }
  }
})(jQuery, Drupal, drupalSettings);

