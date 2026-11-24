using Microsoft.Win32;

namespace EduTaskNotifier;

/// <summary>Per-user "start with Windows", pointing at Velopack's stable root executable.</summary>
internal static class Autostart
{
    private const string RunKey = @"Software\Microsoft\Windows\CurrentVersion\Run";
    private const string ValueName = "EduTaskNotifier";

    private static string ExePath
    {
        get
        {
            var current = Environment.ProcessPath!;
            var dir = Path.GetDirectoryName(current)!;
            if (!string.Equals(Path.GetFileName(dir), "current", StringComparison.OrdinalIgnoreCase))
            {
                return current;
            }
            // Velopack names the root launcher after the pack title, and it
            // keeps working while an update replaces the "current" folder.
            var stub = Directory.GetFiles(Path.GetDirectoryName(dir)!, "*.exe")
                .FirstOrDefault(f => !string.Equals(Path.GetFileName(f), "Update.exe", StringComparison.OrdinalIgnoreCase));
            return stub ?? current;
        }
    }

    public static bool IsEnabled()
    {
        using var key = Registry.CurrentUser.OpenSubKey(RunKey);
        return key?.GetValue(ValueName) is string;
    }

    public static void Set(bool enabled)
    {
        using var key = Registry.CurrentUser.CreateSubKey(RunKey);
        if (enabled)
        {
            key.SetValue(ValueName, $"\"{ExePath}\"");
        }
        else
        {
            key.DeleteValue(ValueName, throwOnMissingValue: false);
        }
    }
}
