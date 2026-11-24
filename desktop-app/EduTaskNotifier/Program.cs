using Microsoft.Toolkit.Uwp.Notifications;
using Velopack;

namespace EduTaskNotifier;

internal static class Program
{
    [STAThread]
    private static void Main()
    {
        // Must run first: Velopack's install/update hooks exit the process here.
        VelopackApp.Build()
            // Not OnFirstRun: that one doesn't fire after a silent install.
            .OnAfterInstallFastCallback(_ => Autostart.Set(true))
            .OnBeforeUninstallFastCallback(_ =>
            {
                Autostart.Set(false);
                try { ToastNotificationManagerCompat.Uninstall(); } catch { /* nothing to clean */ }
            })
            .Run();

        using var single = new Mutex(true, @"Local\EduTaskNotifier.SingleInstance", out var isFirst);
        if (!isFirst)
        {
            return;
        }

        ApplicationConfiguration.Initialize();
        Application.Run(new TrayApp());
    }
}
