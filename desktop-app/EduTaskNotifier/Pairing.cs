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
                await RespondAsync(client, 400, BuildPage(PageKind.Error, "คำขอไม่ถูกต้อง",
                    "ลิงก์นี้ไม่ตรงกับการเชื่อมต่อที่กำลังรออยู่", "กรุณาเริ่มเชื่อมต่อใหม่จากไอคอนแอปในถาดระบบ", server), timeout.Token);
                continue; // Not our browser redirect: keep waiting for the real one.
            }
            var code = query["code"];
            if (string.IsNullOrEmpty(code))
            {
                await RespondAsync(client, 200, BuildPage(PageKind.Cancelled, "ยกเลิกการเชื่อมต่อแล้ว",
                    "เครื่องนี้จะยังไม่รับแจ้งเตือนงาน", "เชื่อมต่อใหม่ได้ทุกเมื่อจากไอคอนแอปในถาดระบบ", server), timeout.Token);
                return null;
            }
            try
            {
                var result = await api.ExchangeCodeAsync(code, verifier, timeout.Token);
                await RespondAsync(client, 200, BuildPage(PageKind.Success, "เชื่อมต่อสำเร็จ",
                    $"เครื่องนี้พร้อมรับแจ้งเตือนงานของ {result.User.FullName}", "ปิดแท็บนี้ได้เลย แอปจะทำงานต่อในถาดระบบ", server), timeout.Token);
                return result;
            }
            catch
            {
                await RespondAsync(client, 502, BuildPage(PageKind.Error, "เชื่อมต่อไม่สำเร็จ",
                    "ไม่สามารถยืนยันอุปกรณ์กับเซิร์ฟเวอร์ได้", "กรุณาลองใหม่จากไอคอนแอปในถาดระบบ", server), CancellationToken.None);
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

    internal enum PageKind { Success, Cancelled, Error }

    /// <summary>Self-contained result page: it is served from loopback, so it loads nothing external.</summary>
    internal static string BuildPage(PageKind kind, string title, string message, string note, string server)
    {
        var (accent, soft, icon) = kind switch
        {
            PageKind.Success => ("#16a34a", "rgba(22,163,74,.14)", "<path d=\"M5 12.5l4.5 4.5L19 7.5\"/>"),
            PageKind.Cancelled => ("#64748b", "rgba(100,116,139,.16)", "<path d=\"M6.5 6.5l11 11M17.5 6.5l-11 11\"/>"),
            _ => ("#dc2626", "rgba(220,38,38,.14)", "<path d=\"M12 6.5v7\"/><path d=\"M12 17.5h.01\"/>"),
        };
        var e = (string s) => WebUtility.HtmlEncode(s);
        return $$"""
            <!doctype html>
            <html lang="th">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{{e(title)}} · EduTask</title>
            <style>
            :root { --card:#fff; --text:#0f172a; --muted:#475569; --faint:#64748b; --line:#e2e8f0; --accent:{{accent}}; --soft:{{soft}};
              --bg:radial-gradient(1200px 500px at 10% -10%,rgba(14,165,233,.35),transparent 60%),linear-gradient(135deg,#13235b 0%,#1e3a8a 55%,#2563eb 100%); }
            @media (prefers-color-scheme: dark) { :root { --card:#111c33; --text:#f1f5f9; --muted:#cbd5e1; --faint:#94a3b8; --line:#26344f;
              --bg:radial-gradient(1200px 500px at 10% -10%,rgba(14,165,233,.18),transparent 60%),linear-gradient(135deg,#070d1f 0%,#0f1b3d 60%,#172f6b 100%); } }
            * { box-sizing:border-box; }
            body { margin:0; min-height:100vh; display:grid; place-items:center; padding:24px 16px; background:var(--bg); color:var(--text);
              font-family:"Leelawadee UI","Segoe UI",Tahoma,sans-serif; }
            main { width:min(440px,100%); background:var(--card); border-radius:18px; padding:34px 30px 24px; text-align:center;
              box-shadow:0 24px 60px rgba(2,6,23,.38); animation:rise .35s ease-out; }
            .brand { display:flex; align-items:center; justify-content:center; gap:8px; color:var(--faint); font-size:13px; font-weight:600; margin-bottom:26px; }
            .logo { width:24px; height:24px; border-radius:7px; display:grid; place-items:center; background:linear-gradient(135deg,#2563eb,#0ea5e9); }
            .logo svg { width:15px; height:15px; fill:none; stroke:#fff; stroke-width:3; stroke-linecap:round; stroke-linejoin:round; }
            .status { width:76px; height:76px; margin:0 auto 18px; border-radius:50%; display:grid; place-items:center; background:var(--soft); }
            .status span { width:50px; height:50px; border-radius:50%; display:grid; place-items:center; background:var(--accent); }
            .status svg { width:30px; height:30px; fill:none; stroke:#fff; stroke-width:2.6; stroke-linecap:round; stroke-linejoin:round; }
            h1 { margin:0 0 8px; font-size:24px; line-height:1.35; font-weight:700; }
            p { margin:0; color:var(--muted); font-size:15px; line-height:1.65; overflow-wrap:anywhere; }
            .note { margin-top:22px; padding-top:16px; border-top:1px solid var(--line); color:var(--faint); font-size:13px; }
            a { display:inline-block; margin-top:12px; padding:6px 10px; border-radius:8px; color:#2563eb; font-size:14px; font-weight:600; text-decoration:none; }
            a:hover { background:rgba(37,99,235,.1); }
            a:focus-visible { outline:2px solid #2563eb; outline-offset:2px; }
            @media (prefers-color-scheme: dark) { a { color:#7fb0ff; } }
            @keyframes rise { from { transform:translateY(8px); } to { transform:none; } }
            @media (prefers-reduced-motion: reduce) { main { animation:none; } }
            </style>
            </head>
            <body>
            <main>
              <div class="brand"><div class="logo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>EduTask Notifier</div>
              <div class="status"><span><svg viewBox="0 0 24 24" aria-hidden="true">{{icon}}</svg></span></div>
              <h1>{{e(title)}}</h1>
              <p>{{e(message)}}</p>
              <p class="note">{{e(note)}}</p>
              <a href="{{e(server + "/")}}">เปิดระบบงาน</a>
            </main>
            <script>history.replaceState(null, "", "/");</script>
            </body>
            </html>
            """;
    }

    private static async Task RespondAsync(TcpClient client, int status, string html, CancellationToken ct)
    {
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
