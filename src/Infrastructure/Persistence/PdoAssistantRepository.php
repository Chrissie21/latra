<?php
namespace Latra\Infrastructure\Persistence;

use PDO;
use Latra\Application\AI\AssistantRepository;

final class PdoAssistantRepository implements AssistantRepository
{
    public function __construct(private PDO $pdo) {}
    public function ensure(): void {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS ai_conversations (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            conversation_id VARCHAR(64) NOT NULL UNIQUE,
            user_id INT NOT NULL,
            title VARCHAR(120) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ai_conv_user (user_id, updated_at)
        ) ENGINE=InnoDB");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS ai_chat_log (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            conversation_id VARCHAR(64) NOT NULL,
            user_id INT NOT NULL,
            role VARCHAR(24) NOT NULL,
            kind VARCHAR(10) NOT NULL DEFAULT 'chat',
            content MEDIUMTEXT NULL,
            tools_used VARCHAR(255) NULL,
            model VARCHAR(60) NULL,
            latency_ms INT NULL,
            tokens INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ai_log_conv (conversation_id, id)
        ) ENGINE=InnoDB");
    }
    public function conversation(string $id,int $user): ?array {
        $stmt=$this->pdo->prepare('SELECT id,title FROM ai_conversations WHERE conversation_id=? AND user_id=?');
        $stmt->execute([$id,$user]);return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function createConversation(string $id,int $user,string $title): void {
        $this->pdo->prepare('INSERT INTO ai_conversations (conversation_id,user_id,title) VALUES (?,?,?)')->execute([$id,$user,$title]);
    }
    public function history(string $id,int $user,int $limit): array {
        $stmt=$this->pdo->prepare("SELECT role,content FROM ai_chat_log WHERE conversation_id=? AND user_id=? AND kind='chat' ORDER BY id DESC LIMIT ".max(1,$limit));
        $stmt->execute([$id,$user]);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function jobs(): array { return $this->pdo->query('SELECT job_key,label FROM jobs ORDER BY job_key')->fetchAll(PDO::FETCH_ASSOC); }
    public function store(array $entry): void {
        $this->pdo->prepare('INSERT INTO ai_chat_log (conversation_id,user_id,role,kind,content,tools_used,model,latency_ms,tokens) VALUES (?,?,?,?,?,?,?,?,?)')->execute([
            $entry['conversation_id'],$entry['user_id'],$entry['role'],$entry['kind'],$entry['content'],
            $entry['tools_used'],$entry['model'],$entry['latency_ms'],$entry['tokens'],
        ]);
    }
}
