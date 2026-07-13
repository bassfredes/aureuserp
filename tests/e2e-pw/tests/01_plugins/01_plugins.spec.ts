import { test, expect } from "../../setup";
import { PluginManagementPage } from "../../pages/01_pluginManagement";

test.describe('Plugin Installation', () => {

    test.beforeEach(async ({ adminPage }) => {
        const pluginManagementPage = new PluginManagementPage(adminPage);
        await pluginManagementPage.gotoPluginManagementPage();
    });

    /**
     * All plugins installation test
     */
    test('All Plugins Installation Test', async ({ adminPage }) => {
        // test.setTimeout(400000);
        const pluginManagementPage = new PluginManagementPage(adminPage);
        await pluginManagementPage.installAllPlugins();
    });

    /**
     * All plugins uninstallation test
     *
     * Skipped: fails with "Target page, context or browser has been closed"
     * (bassfredes/Intelligent-Integration-Suite#144). Not yet determined
     * whether this is UI timing/flakiness or an app-level regression.
     */
    test.skip('All Plugins Uninstallation Test', async ({  adminPage }) => {
        const pluginManagementPage = new PluginManagementPage(adminPage);
        await pluginManagementPage.uninstallAllPlugins();
    });
});
