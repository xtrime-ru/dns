<?php

namespace Amp\Dns;

use Amp\Cache\ArrayCache;
use Amp\Cache\Cache;
use Amp\Coroutine;
use Amp\MultiReasonException;
use Amp\Promise;
use Amp\Success;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\Question;
use LibDNS\Records\QuestionFactory;
use function Amp\call;

class BasicResolver implements Resolver {
    const CACHE_PREFIX = "amphp.dns.";

    /** @var \Amp\Dns\ConfigLoader */
    private $configLoader;

    /** @var \LibDNS\Records\QuestionFactory */
    private $questionFactory;

    /** @var \Amp\Dns\Config|null */
    private $config;

    /** @var Cache */
    private $cache;

    /** @var array */
    private $servers = [];

    public function __construct(Cache $cache = null, ConfigLoader $configLoader = null) {
        $this->cache = $cache ?? new ArrayCache;
        $this->configLoader = $configLoader ?? \stripos(PHP_OS, "win") === 0
                ? new WindowsConfigLoader
                : new UnixConfigLoader;

        $this->questionFactory = new QuestionFactory;
    }

    /** @inheritdoc */
    public function resolve(string $name, int $typeRestriction = null): Promise {
        if ($typeRestriction !== null && $typeRestriction !== Record::A && $typeRestriction !== Record::AAAA) {
            throw new \Error("Invalid value for parameter 2: null|Record::A|Record::AAAA expected");
        }

        return call(function () use ($name, $typeRestriction) {
            if (!$this->config) {
                $this->config = yield $this->configLoader->loadConfig();
            }

            $inAddr = @\inet_pton($name);

            if ($inAddr !== false) {
                // It's already a valid IP, don't query, immediately return
                return [
                    new Record($name, isset($inAddr[4]) ? Record::AAAA : Record::A, null)
                ];
            }

            $name = normalizeDnsName($name);

            if ($records = $this->queryHosts($name, $typeRestriction)) {
                return $records;
            }

            if ($typeRestriction) {
                return $this->query($name, $typeRestriction);
            }

            try {
                list(, $records) = yield Promise\some([
                    $this->query($name, Record::A),
                    $this->query($name, Record::AAAA),
                ]);
            } catch (MultiReasonException $e) {
                throw new ResolutionException("All query attempts failed", 0, $e);
            }

            return array_merge(...$records);
        });
    }

    private function queryHosts(string $name, int $typeRestriction = null): array {
        $hosts = $this->config->getKnownHosts();
        $records = [];

        $returnIPv4 = $typeRestriction === null || $typeRestriction === Record::A;
        $returnIPv6 = $typeRestriction === null || $typeRestriction === Record::AAAA;

        if ($returnIPv4 && isset($hosts[Record::A][$name])) {
            $records[] = new Record($hosts[Record::A][$name], Record::A, null);
        }

        if ($returnIPv6 && isset($hosts[Record::AAAA][$name])) {
            $records[] = new Record($hosts[Record::AAAA][$name], Record::AAAA, null);
        }

        return $records;
    }

    /** @inheritdoc */
    public function query(string $name, int $type): Promise {
        return new Coroutine($this->doQuery($name, $type));
    }

    public function doQuery(string $name, int $type): \Generator {
        if (!$this->config) {
            $this->config = yield $this->configLoader->loadConfig();
        }

        $name = $this->normalizeName($name, $type);
        $question = $this->createQuestion($name, $type);

        if (null !== $cachedValue = yield $this->cache->get($this->getCacheKey($name, $type))) {
            return $this->decodeCachedResult($name, $type, $cachedValue);
        }

        $nameservers = $this->config->getNameservers();
        $attempts = $this->config->getAttempts();
        $receivedTruncatedResponse = false;

        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            $i = $attempt % \count($nameservers);
            $protocol = $receivedTruncatedResponse ? "tcp" : "udp";

            /** @var \Amp\Dns\Server $server */
            $server = yield $this->getServer($protocol . "://" . $nameservers[$i]);

            if (!$server->isAlive()) {
                unset($this->servers[$protocol . "://" . $nameservers[$i]]);

                /** @var \Amp\Dns\Server $server */
                $server = yield $this->getServer($protocol . "://" . $nameservers[$i]);
            }

            /** @var Message $response */
            $response = yield $server->ask($question);
            $this->assertAcceptableResponse($response);

            if ($response->isTruncated()) {
                if (!$receivedTruncatedResponse) {
                    // Retry with TCP, don't count attempt
                    $receivedTruncatedResponse = true;
                    $attempt--;
                    continue;
                }

                throw new ResolutionException("Server returned truncated response");
            }

            $answers = $response->getAnswerRecords();
            $result = [];
            $ttls = [];

            /** @var \LibDNS\Records\Resource $record */
            foreach ($answers as $record) {
                $recordType = $record->getType();

                $result[$recordType][] = $record->getData();
                $ttls[$recordType] = \min($ttls[$recordType] ?? \PHP_INT_MAX, $record->getTTL());
            }

            foreach ($result as $recordType => $records) {
                // We don't care here whether storing in the cache fails
                $this->cache->set($this->getCacheKey($name, $recordType), \json_encode($records), $ttls[$recordType]);
            }

            if (!isset($result[$type])) {
                // "it MUST NOT cache it for longer than five (5) minutes" per RFC 2308 section 7.1
                $this->cache->set($this->getCacheKey($name, $type), \json_encode([]), 300);
                throw new NoRecordException("No records returned for {$name}");
            }

            return array_map(function ($data) use ($type, $ttls) {
                return new Record($data, $type, $ttls[$type]);
            }, $result[$type]);
        }

        throw new ResolutionException("No response from any nameserver after {$attempts} attempts");
    }

    /**
     * @param string $name
     * @param int    $type
     *
     * @return \LibDNS\Records\Question
     */
    private function createQuestion(string $name, int $type): Question {
        if (0 > $type || 0xffff < $type) {
            $message = \sprintf('%d does not correspond to a valid record type (must be between 0 and 65535).', $type);
            throw new \Error($message);
        }

        $question = $this->questionFactory->create($type);
        $question->setName($name);

        return $question;
    }

    private function getCacheKey(string $name, int $type): string {
        return self::CACHE_PREFIX . $name . "#" . $type;
    }

    private function decodeCachedResult(string $name, string $type, string $encoded) {
        $decoded = \json_decode($encoded, true);

        if (!$decoded) {
            throw new NoRecordException("No records returned for {$name} (cached result)");
        }

        $result = [];

        foreach ($decoded as $data) {
            $result[] = new Record($data, $type);
        }

        return $result;
    }

    private function normalizeName(string $name, int $type) {
        if ($type === Record::PTR) {
            if (($packedIp = @inet_pton($name)) !== false) {
                if (isset($packedIp[4])) { // IPv6
                    $name = wordwrap(strrev(bin2hex($packedIp)), 1, ".", true) . ".ip6.arpa";
                } else { // IPv4
                    $name = inet_ntop(strrev($packedIp)) . ".in-addr.arpa";
                }
            }
        } else if (\in_array($type, [Record::A, Record::AAAA])) {
            $name = normalizeDnsName($name);
        }

        return $name;
    }

    private function getServer($uri): Promise {
        if (isset($this->servers[$uri])) {
            return $this->servers[$uri];
        }

        if (substr($uri, 0, 3) === "udp") {
            $server = UdpServer::connect($uri);
        } else {
            $server = TcpServer::connect($uri);
        }

        $server->onResolve(function ($error) use ($uri) {
            if ($error) {
                unset($this->servers[$uri]);
            }
        });

        return $server;
    }

    private function assertAcceptableResponse(Message $response) {
        if ($response->getResponseCode() !== 0) {
            throw new ResolutionException(\sprintf("Server returned error code: %d", $response->getResponseCode()));
        }

        if ($response->getType() !== MessageTypes::RESPONSE) {
            throw new ResolutionException("Invalid server reply; expected RESPONSE but received QUERY");
        }
    }
}
