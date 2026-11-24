using System.Text.Json;

namespace EduTaskNotifier;

/// <summary>Non-secret state. The token lives in Windows Credential Manager.</summary>
internal sealed class AppSettings
{
    public const string DefaultServer = "https://wiak.rvc.ac.th/web";

    public long Cursor { get; set; }
    public long? MessageCursor { get; set; }
    public string? UserName { get; set; }

    private static string FilePath => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "EduTaskNotifierData", "settings.json");

    /// <summary>EDUTASK_SERVER is for development; a token is never sent over plain http off this machine.</summary>
    public static string Server()
    {
        var value = Environment.GetEnvironmentVariable("EDUTASK_SERVER");
        if (!string.IsNullOrWhiteSpace(value)
            && Uri.TryCreate(value.Trim(), UriKind.Absolute, out var uri)
            && (uri.Scheme == Uri.UriSchemeHttps || (uri.Scheme == Uri.UriSchemeHttp && uri.IsLoopback)))
        {
            return value.Trim().TrimEnd('/');
        }
        return DefaultServer;
    }

    public static AppSettings Load()
    {
        try
        {
            return JsonSerializer.Deserialize<AppSettings>(File.ReadAllText(FilePath)) ?? new AppSettings();
        }
        catch (Exception e) when (e is IOException or JsonException or UnauthorizedAccessException)
        {
            return new AppSettings();
        }
    }

    public void Save()
    {
        Directory.CreateDirectory(Path.GetDirectoryName(FilePath)!);
        var temp = FilePath + ".tmp";
        File.WriteAllText(temp, JsonSerializer.Serialize(this));
        File.Move(temp, FilePath, overwrite: true);
    }
}
