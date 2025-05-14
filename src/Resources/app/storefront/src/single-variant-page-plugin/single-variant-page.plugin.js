import VariantSwitchPlugin from "src/plugin/variant-switch/variant-switch.plugin";
import ElementReplaceHelper from "src/helper/element-replace.helper";
import PageLoadingIndicatorUtil from 'src/utility/loading-indicator/page-loading-indicator.util';

export default class SingleVariantPagePlugin extends VariantSwitchPlugin {
    _redirectToVariant(data) {
        PageLoadingIndicatorUtil.create();

        let url = this.options.url + '?' + (new URLSearchParams(data)).toString();

        fetch(url)
            .then(response => response.text())
            .then(response => {
                const srcEl = new DOMParser().parseFromString(response, 'text/html');
                ElementReplaceHelper.replaceElement(srcEl.querySelector('#content-main'), '#content-main', true);
                window.PluginManager.initializePlugins();
                PageLoadingIndicatorUtil.remove();
            });
    }
}
