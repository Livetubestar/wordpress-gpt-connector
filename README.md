# 📝 WordPress GPT Connector

**ChatGPT Custom GPT × WordPress REST API で実現する、記事自動投稿システム**

LivetubeSTARブログ向けに個人開発した、ChatGPTから直接WordPressへ記事を投稿・更新できる仕組みの設計・構成まとめです。

---

## これは何をする仕組みか

ChatGPT上でカスタムGPT（Custom GPT）に記事の内容を指示すると、GPTが記事本文を生成し、WordPress REST APIを通じてブログへ自動投稿（または下書き保存）します。

```
[あなた] → [ChatGPT Custom GPT] → [WordPress REST API] → [ブログ記事]
```

音声入力や会話ベースで記事の指示ができるため、キーボード入力なしでもブログ運営が可能になります。

---

## なぜ作ったか

- WordPressの管理画面を毎回開いて記事を入力する手間を減らしたかった
- ChatGPTで下書きを作っても、コピペが面倒だった
- 音声入力ベースのコンテンツ作成フローを実現したかった
- AIとのやり取り自体を記事にする「AI活用記事」を自然に作りたかった

---

## 技術構成

| 要素 | 内容 |
|------|------|
| フロントエンド | ChatGPT Custom GPT（GPT-4o） |
| API連携 | OpenAPI 3.1.0 スキーマで定義したカスタムアクション |
| バックエンド | WordPress REST API（カスタムプラグイン） |
| 認証 | APIキー認証（`X-API-Key` ヘッダー） |
| 投稿形式 | HTML形式の本文をWordPressへ直接送信 |

---

## GPTとWordPressのつながり方

1. ChatGPTのカスタムGPTに「OpenAPIアクション」として `/gpt/openapi.yaml` を登録する
2. GPTが記事を生成したら、`createPost` アクションでWordPressへPOSTリクエストを送信する
3. WordPress側のカスタムプラグインがリクエストを受け取り、記事を作成・保存する
4. GPTはWordPressから返ってくる記事IDや投稿URLを受け取り、確認をユーザーに伝える

---

## 下書き投稿を基本にしている理由

- AIが生成した記事を即公開するのはリスクがある
- 内容・タイトル・カテゴリを自分で確認してから公開したい
- `status: draft` をデフォルトにすることで、誤公開を防止している
- 確認後に管理画面から手動で公開するフローが安全

---

## AI活用記事と通常体験記事の分岐設計

このGPTはLivetubeSTARブログの記事スタイルに合わせて、記事の種類を自動判定します。

| 記事タイプ | 対象内容 | 特徴 |
|-----------|---------|------|
| AI活用記事 | ChatGPT・Claude・自動化・WordPress連携など | 会話ログ・試行錯誤を含む、過程重視の構成 |
| 通常体験記事 | グルメ・レビュー・お出かけ・日常記録など | 体験ベースの自然な記事構成 |

GPTが判定し、それぞれに適したテンプレートで記事を生成します。

---

## セキュリティ上の注意点

- **APIキーは絶対にコードにハードコードしない**
- WordPressプラグインのAPIキーは `wp-config.php` または環境変数で管理する
- `.env` ファイルは `.gitignore` で除外すること
- ChatGPT側のAPIキー設定は「シークレット」として登録する
- 不審なリクエストを弾くためにIPアドレス制限も検討する（オプション）

`.env.example` を参考に `.env` ファイルを作成してください。実際のAPIキーは絶対にコミットしないこと。

---

## ファイル構成

```
wordpress-gpt-connector/
├── README.md                          # このファイル
├── .env.example                       # 環境変数のテンプレート
├── .gitignore                         # Git除外設定
├── LICENSE                            # MITライセンス
├── docs/
│   ├── overview.md                    # システム概要と設計思想
│   └── workflow.md                    # 記事作成から投稿までの流れ
├── gpt/
│   ├── instructions.md                # GPT Instructions（カスタムGPT設定用）
│   └── openapi.yaml                   # OpenAPIスキーマ（ChatGPTアクション定義）
└── wordpress-plugin/
    └── livetubestar-site-control.php  # WordPress カスタムプラグイン
```

---

## セットアップ手順

### 1. WordPressプラグインの設置

```bash
# wordpress-plugin/livetubestar-site-control.php を
# WordPressの wp-content/plugins/livetubestar-site-control/ に配置
```

WordPress管理画面 → プラグイン → 有効化

### 2. APIキーの設定

`wp-config.php` に以下を追加：

```php
define('LTS_API_KEY', 'your-secret-api-key-here');
```

### 3. ChatGPT Custom GPTの設定

1. ChatGPT → Explore GPTs → Create a GPT
2. Instructions に `/gpt/instructions.md` の内容を貼り付ける
3. Actions → Create new action
4. `/gpt/openapi.yaml` の内容を貼り付ける
5. Authentication → API Key → `X-API-Key` ヘッダーに APIキーを設定

---

## 今後やりたいこと

- [ ] アイキャッチ画像の自動生成・設定
- [ ] 音声入力からのワンステップ投稿
- [ ] 記事のSEOスコア確認機能
- [ ] Claude（Cowork）との連携バージョン
- [ ] 投稿履歴の管理・一覧表示機能

---

## 作者

**LivetubeSTAR / 屈辱**
ブログ: [livetubestar.com](https://livetubestar.com)
GitHub: [@Livetubestar](https://github.com/Livetubestar)

---

*このリポジトリは個人開発の成果物として公開しています。コードは自由に参考にしてください（MITライセンス）。*
