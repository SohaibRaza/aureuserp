<?php

it('resolves Webkul and non-Webkul plugin roots', function (): void {
    $pluginsRoot = sys_get_temp_dir().'/aureuserp-plugin-path-'.bin2hex(random_bytes(8));

    try {
        mkdir($pluginsRoot.'/webkul/accounts', 0700, true);
        mkdir($pluginsRoot.'/shafique/logistics', 0700, true);

        expect(TestBootstrapHelper::pluginRootPath('accounts', $pluginsRoot))
            ->toBe($pluginsRoot.'/webkul/accounts')
            ->and(TestBootstrapHelper::pluginRootPath('logistics', $pluginsRoot))
            ->toBe($pluginsRoot.'/shafique/logistics');
    } finally {
        rmdir($pluginsRoot.'/webkul/accounts');
        rmdir($pluginsRoot.'/webkul');
        rmdir($pluginsRoot.'/shafique/logistics');
        rmdir($pluginsRoot.'/shafique');
        rmdir($pluginsRoot);
    }
});
