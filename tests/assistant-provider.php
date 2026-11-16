<?php
namespace Latra\Infrastructure\AI {
    final class ProviderFixture {
        public static array $options=[];
        public static string|false $raw='';
        public static int $http=200;
        public static string $error='';
    }
    function curl_init(string $url): object { return (object)['url'=>$url]; }
    function curl_setopt_array(object $handle,array $options): bool { ProviderFixture::$options=$options;return true; }
    function curl_exec(object $handle): string|false { return ProviderFixture::$raw; }
    function curl_getinfo(object $handle,int $key): int { return ProviderFixture::$http; }
    function curl_error(object $handle): string { return ProviderFixture::$error; }
}
namespace {
    if (PHP_SAPI !== 'cli') exit(1);
    require __DIR__.'/../includes/autoload.php';
    use Latra\Infrastructure\AI\OpenAiChatClient;
    use Latra\Infrastructure\AI\ProviderFixture;
    function providerAssert(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
    $missing=new OpenAiChatClient('');
    providerAssert($missing->chat([])['error']['code']==='AI_NOT_CONFIGURED' && ProviderFixture::$options===[], 'Unconfigured client attempted provider I/O');
    $client=new OpenAiChatClient('synthetic-key','synthetic-model',45,'https://synthetic.invalid');
    ProviderFixture::$raw='{"choices":[{"message":{"role":"assistant","content":"synthetic answer"}}],"usage":{"total_tokens":12}}';
    $answer=$client->chat([['role'=>'user','content'=>'fixture']],[],1);
    $body=json_decode(ProviderFixture::$options[CURLOPT_POSTFIELDS],true,512,JSON_THROW_ON_ERROR);
    providerAssert($answer['ok'] && $answer['message']['content']==='synthetic answer' && $body['model']==='synthetic-model'
        && $body['max_tokens']===64 && !isset($body['tools'])
        && ProviderFixture::$options[CURLOPT_TIMEOUT]===45 && ProviderFixture::$options[CURLOPT_CONNECTTIMEOUT]===15
        && ProviderFixture::$options[CURLOPT_SSL_VERIFYPEER]===true && ProviderFixture::$options[CURLOPT_SSL_VERIFYHOST]===2, 'Provider payload, bounds or TLS contract changed');
    ProviderFixture::$raw='not json';
    providerAssert($client->chat([])['error']['code']==='AI_EMPTY_RESPONSE', 'Malformed successful provider response accepted');
    ProviderFixture::$http=429;ProviderFixture::$raw='{"error":{"code":"synthetic_limit","message":"synthetic reason"}}';
    providerAssert($client->chat([])['error']['code']==='synthetic_limit', 'Provider error response lost');
    ProviderFixture::$raw=false;ProviderFixture::$error='synthetic network failure';
    providerAssert($client->chat([])['error']['code']==='AI_SERVICE_UNAVAILABLE', 'Provider connection failure accepted');
    echo "PASS AI provider request/TLS/timeout contract and malformed, HTTP and connection failures without network\n";
}
