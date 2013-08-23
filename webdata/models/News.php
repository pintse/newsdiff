<?php

class NewsRow extends Pix_Table_Row
{
    public function getFirstRaw()
    {
        return NewsRaw::search(array('news_id' => $this->id))->order('time ASC')->first();
    }

    public function generateDiff($reset = true)
    {
        if ($reset) {
            $this->diffs->delete();
            $this->infos->delete();
        }

        $last_code = '';

        // 先取得最新一筆 info
        if (!$last_info = $this->infos->order('time DESC')->first()) {
            // 沒有最新一筆就先把最舊的塞進去
            $raw = NewsRaw::search(array('news_id' => $this->id))->order('time ASC')->first();
            $ret = $raw->getInfo();
            $last_info = NewsInfo::insert(array(
                'news_id' => $this->id,
                'time' => $raw->time,
                'title' => $ret->title,
                'body' => $ret->body,
            ));
            if ($ret->title == $ret->body and strlen($ret->body) < 10) {
                $last_code = $ret->title;
            }
        }

        $last_changed_at = 0;

        foreach (NewsRaw::search(array('news_id' => $this->id))->order('time ASC')->after(array('time' => $this->last_diff_at)) as $raw) {
            $ret = $raw->getInfo();

            if ($ret->title == $ret->body and strlen($ret->body) < 10) {
                if ($last_code == $ret->title) {
                    continue;
                }
                try {
                    NewsDiff::insert(array(
                        'news_id' => $this->id,
                        'time' => $raw->time,
                        'column' => 0,
                        'diff' => $ret->title,
                    ));
                } catch (Pix_Table_DuplicateException $e) {
                    NewsDiff::find(array($this->id, $raw->time, 0))->update(array(
                        'diff' => $ret->title,
                    ));
                }

                NewsInfo::insert(array(
                    'news_id' => $this->id,
                    'time' => $raw->time,
                    'title' => $ret->title,
                    'body' => $ret->title,
                ));

                $last_code = $ret->title;
                continue;
            }

            $changed = false;

            if (!$last_info and $last_info->title!= $ret->title) {
                $changed = true;
                try {
                    NewsDiff::insert(array(
                        'news_id' => $this->id,
                        'time' => $raw->time,
                        'column' => 0,
                        'diff' => '',
                    ));
                } catch (Pix_Table_DuplicateException $e) {
                    NewsDiff::find(array($this->id, $raw->time, 0))->update(array(
                        'diff' => '',
                    ));
                }
            }

            if (!is_null($last_info) and $last_info->body != $ret->body) {
                $changed = true;
                try {
                    NewsDiff::insert(array(
                        'news_id' => $this->id,
                        'time' => $raw->time,
                        'column' => 1,
                        'diff' => '',
                    ));
                } catch (Pix_Table_DuplicateException $e) {
                    NewsDiff::find(array($this->id, $raw->time, 1))->update(array(
                        'diff' => '',
                    ));
                }
            }


            if ($changed) {
                $last_changed_at = $raw->time;
                $info = NewsInfo::insert(array(
                    'news_id' => $this->id,
                    'time' => $raw->time,
                    'title' => $ret->title,
                    'body' => $ret->body,
                ));
                $last_info = $info;
            }
        }

        $this->update(arraY(
            'diff_count' => count($this->diffs),
            'last_changed_at' => $last_changed_at,
            'last_diff_at' => $raw->time,
        ));
    }
}

class News extends Pix_Table
{
    public function init()
    {
        $this->_name = 'news';
        $this->_primary = 'id';
        $this->_rowClass = 'NewsRow';

        $this->_columns['id'] = array('type' => 'int', 'auto_increment' => true);
        $this->_columns['url'] = array('type' => 'varchar', 'size' => 255);
        $this->_columns['url_crc32'] = array('type' => 'int');
        // 新聞來源
        $this->_columns['source'] = array('type' => 'tinyint');
        $this->_columns['created_at'] = array('type' => 'int');
        $this->_columns['last_fetch_at'] = array('type' => 'int');
        $this->_columns['diff_count'] = array('type' => 'int');
        $this->_columns['last_changed_at'] = array('type' => 'int');
        $this->_columns['last_diff_at'] = array('type' => 'int');

        $this->_relations['raws'] = array('rel' => 'has_many', 'type' => 'NewsRaw', 'foreign_key' => 'news_id', 'delete' => true);
        $this->_relations['diffs'] = array('rel' => 'has_many', 'type' => 'NewsDiff', 'foreign_key' => 'news_id', 'delete' => true);
        $this->_relations['infos'] = array('rel' => 'has_many', 'type' => 'NewsInfo', 'foreign_key' => 'news_id', 'delete' => true);

        $this->addIndex('url_crc32', array('url_crc32'), 'unique');
    }

    public function addNews($url, $source)
    {
        $url_crc32 = crc32($url);
        if (News::find_by_url_crc32($url_crc32)) {
            return;
        }

        try {
            News::insert(array(
                'url' => $url,
                'url_crc32' => $url_crc32,
                'source' => $source,
                'created_at' => time(),
                'last_fetch_at' => 0,
                'diff_count' => 0,
            ));
        } catch (Pix_Table_DuplicateException $e) {
        }
    }

    public static function getSources()
    {
        return array(
            1 => '蘋果',
            2 => '中時',
            3 => '中央社',
            4 => '東森',
            5 => '自由',
            6 => '新頭殼',
            7 => 'NowNews',
            8 => '聯合',
        );
    }
}
