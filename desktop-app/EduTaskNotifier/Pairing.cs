using System.Diagnostics;
using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Web;

namespace EduTaskNotifier;

/// <summary>Browser approval with a one-time code returned to a loopback port (PKCE).</summary>
internal static class Pairing
{
    private static readonly TimeSpan Timeout = TimeSpan.FromMinutes(5);

    public static async Task<TokenResponse?> RunAsync(ApiClient api, string server, CancellationToken ct, Action<string>? open = null)
    {
        var verifier = Base64Url(RandomNumberGenerator.GetBytes(32));
        var challenge = Base64Url(SHA256.HashData(Encoding.ASCII.GetBytes(verifier)));
        var state = Base64Url(RandomNumberGenerator.GetBytes(24));

        using var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        var port = ((IPEndPoint)listener.LocalEndpoint).Port;

        (open ?? OpenBrowser)($"{server}/desktop/pair?challenge={challenge}&state={state}&port={port}"
            + "&device_name=" + Uri.EscapeDataString(Environment.MachineName));

        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
        timeout.CancelAfter(Timeout);
        while (true)
        {
            using var client = await listener.AcceptTcpClientAsync(timeout.Token);
            var query = await ReadCallbackQueryAsync(client, timeout.Token);
            if (query is null || query["state"] != state)
            {
                await RespondAsync(client, 400, "คำขอไม่ถูกต้อง", timeout.Token);
                continue; // Not our browser redirect: keep waiting for the real one.
            }
            var code = query["code"];
            if (string.IsNullOrEmpty(code))
            {
                await RespondAsync(client, 200, "ยกเลิกการเชื่อมต่อแล้ว ปิดหน้านี้ได้", timeout.Token);
                return null;
            }
            try
            {
                var result = await api.ExchangeCodeAsync(code, verifier, timeout.Token);
                await RespondAsync(client, 200, "เชื่อมต่อแอปแจ้งเตือนสำเร็จ ปิดหน้านี้ได้", timeout.Token);
                return result;
            }
            catch
            {
                await RespondAsync(client, 502, "เชื่อมต่อไม่สำเร็จ กรุณาลองใหม่จากแอป", CancellationToken.None);
                throw;
            }
        }
    }

    public static void OpenBrowser(string url) =>
        Process.Start(new ProcessStartInfo(url) { UseShellExecute = true })?.Dispose();

    private static async Task<System.Collections.Specialized.NameValueCollection?> ReadCallbackQueryAsync(TcpClient client, CancellationToken ct)
    {
        var stream = client.GetStream();
        var buffer = new byte[8192];
        var total = 0;
        while (total < buffer.Length)
        {
            var read = await stream.ReadAsync(buffer.AsMemory(total), ct);
            if (read == 0)
            {
                break;
            }
            total += read;
            if (Encoding.ASCII.GetString(buffer, 0, total).Contains("\r\n\r\n"))
            {
                break;
            }
        }
        var line = Encoding.ASCII.GetString(buffer, 0, total).Split("\r\n")[0].Split(' ');
        if (line.Length < 2 || line[0] != "GET" || !line[1].StartsWith("/callback?", StringComparison.Ordinal))
        {
            return null;
        }
        return HttpUtility.ParseQueryString(line[1]["/callback?".Length..]);
    }

    private static async Task RespondAsync(TcpClient client, int status, string message, CancellationToken ct)
    {
        var html = "<!doctype html><meta charset=\"utf-8\"><title>EduTask Notifier</title>"
            + "<body style=\"font-family:sans-serif;max-width:480px;margin:80px auto;text-align:center\"><p>"
            + WebUtility.HtmlEncode(message) + "</p></body>";
        var body = Encoding.UTF8.GetBytes(html);
        var head = Encoding.ASCII.GetBytes(
            $"HTTP/1.1 {status} {(status == 200 ? "OK" : "Error")}\r\nContent-Type: text/html; charset=utf-8\r\n"
            + $"Content-Length: {body.Length}\r\nCache-Control: no-store\r\nReferrer-Policy: no-referrer\r\nConnection: close\r\n\r\n");
        var stream = client.GetStream();
        await stream.WriteAsync(head, ct);
        await stream.WriteAsync(body, ct);
    }

    private static string Base64Url(byte[] bytes) =>
        Convert.ToBase64String(bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_');
}
