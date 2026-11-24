using System.Net;
using System.Net.Http.Headers;
using System.Reflection;
using System.Text.Json;

namespace EduTaskNotifier;

internal sealed class InvalidTokenException : Exception;

internal sealed record ApiUser(int Id, string FullName);
internal sealed record TokenResponse(bool Ok, string Token, long Cursor, ApiUser User, int PollSeconds, long? MessageCursor);
internal sealed record MessageItem(long Id, int ConversationId, ApiUser Sender, string Preview, string OpenPath);
internal sealed record FeedTicket(int Id, string Code, string Title);
internal sealed record FeedItem(long Id, string Type, string Heading, string Body, FeedTicket Ticket, string OpenPath);
internal sealed record FeedSummary(int Urgent, int Warning, int Edits);
internal sealed record FeedResponse(
    bool Ok, List<FeedItem> Items, long Cursor, bool HasMore, int Unread,
    FeedSummary Summary, ApiUser User, int PollSeconds,
    List<MessageItem>? Messages, long? MessageCursor, int UnreadMessages);

internal sealed class ApiClient : IDisposable
{
    private static readonly JsonSerializerOptions Json = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        PropertyNameCaseInsensitive = true,
    };

    private readonly HttpClient _http;
    private readonly string _server;

    public ApiClient(string server)
    {
        _server = server;
        // No redirects: a bearer token must only ever go to the configured URL.
        _http = new HttpClient(new HttpClientHandler { AllowAutoRedirect = false, UseCookies = false })
        {
            Timeout = TimeSpan.FromSeconds(30),
        };
        _http.DefaultRequestHeaders.UserAgent.ParseAdd("EduTaskNotifier/" + Version);
        _http.DefaultRequestHeaders.Add("X-App-Version", Version);
    }

    public static string Version =>
        (Assembly.GetExecutingAssembly().GetCustomAttribute<AssemblyInformationalVersionAttribute>()?.InformationalVersion ?? "0.0.0")
        .Split('+')[0];

    public async Task<TokenResponse> ExchangeCodeAsync(string code, string verifier, CancellationToken ct)
    {
        using var content = new FormUrlEncodedContent(new Dictionary<string, string>
        {
            ["code"] = code,
            ["code_verifier"] = verifier,
        });
        using var response = await _http.PostAsync(_server + "/api/desktop/token", content, ct);
        return await ReadAsync<TokenResponse>(response, ct);
    }

    /// <param name="afterMessage">null until the app has a chat cursor; the server then returns only the cursor.</param>
    public async Task<FeedResponse> PollAsync(string token, long after, long? afterMessage, CancellationToken ct)
    {
        var url = $"{_server}/api/desktop/notifications?after={after}&limit=50"
            + (afterMessage is { } m ? $"&after_message={m}" : "");
        using var request = new HttpRequestMessage(HttpMethod.Get, url);
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        using var response = await _http.SendAsync(request, ct);
        return await ReadAsync<FeedResponse>(response, ct);
    }

    public async Task MarkAllReadAsync(string token, CancellationToken ct)
    {
        using var request = new HttpRequestMessage(HttpMethod.Post, _server + "/api/desktop/notifications/read")
        {
            Content = new FormUrlEncodedContent(new Dictionary<string, string> { ["all"] = "1" }),
        };
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        using var response = await _http.SendAsync(request, ct);
        await EnsureAsync(response);
    }

    public async Task RevokeAsync(string token, CancellationToken ct)
    {
        using var request = new HttpRequestMessage(HttpMethod.Post, _server + "/api/desktop/revoke");
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        using var response = await _http.SendAsync(request, ct);
        await EnsureAsync(response);
    }

    private static async Task<T> ReadAsync<T>(HttpResponseMessage response, CancellationToken ct)
    {
        await EnsureAsync(response);
        await using var stream = await response.Content.ReadAsStreamAsync(ct);
        return await JsonSerializer.DeserializeAsync<T>(stream, Json, ct)
            ?? throw new HttpRequestException("Empty response");
    }

    private static Task EnsureAsync(HttpResponseMessage response)
    {
        if (response.StatusCode == HttpStatusCode.Unauthorized)
        {
            throw new InvalidTokenException();
        }
        if (!response.IsSuccessStatusCode)
        {
            throw new HttpRequestException($"Server returned {(int)response.StatusCode}", null, response.StatusCode);
        }
        return Task.CompletedTask;
    }

    public void Dispose() => _http.Dispose();
}
