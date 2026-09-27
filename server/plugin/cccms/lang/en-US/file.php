<?php

declare(strict_types=1);

/** Attachment management messages. */
return [
    'not_found'            => 'Attachment not found',
    'file_required'        => 'Please select a file',
    'uploaded'             => 'Uploaded successfully',
    'no_permission'        => 'You are not allowed to delete this attachment',
    'move_select_required' => 'Please select the attachments to move',
    'category_not_found'   => 'Target category not found',
    'file_name_required'   => 'File name is required',
    'file_empty'           => 'The file is empty',
    'chunk_size_exceeded'  => 'File exceeds the size limit (max {max}MB)',
    'chunk_count_invalid'  => 'Invalid chunk count (expected 1 to {max} chunks)',
    'chunk_dir_failed'     => 'Failed to create the chunk temp directory, please check runtime permissions',
    'chunk_index_invalid'  => 'Invalid chunk index (expected 0 to {max})',
    'chunk_upload_failed'  => 'Chunk upload failed, please retry this chunk',
    'chunk_too_large'      => 'Chunk exceeds the limit (max {max}MB)',
    'chunk_missing'        => '{count} chunk(s) missing (e.g. chunk {first}), please resume and submit again',
    'chunk_size_mismatch'  => 'Merged size ({actual} bytes) does not match the declared size ({expected} bytes), please upload again',
    'chunk_hash_mismatch'  => 'Content does not match the declared hash: a chunk may be corrupted, please upload again',
    'chunk_merge_failed'   => 'Failed to merge chunks, please retry',
    'chunk_redis_required' => 'Chunked upload requires Redis (used to keep the upload session)',
    'upload_session_invalid' => 'Invalid upload session',
    'upload_session_expired' => 'Upload session not found or expired, please upload again',
];
