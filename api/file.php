<?php
namespace Api;

/**
 * @desc 与文件相关
 */

class file extends app {
	/**
	 * @access public
	 * @desc 上传私聊文件
	 * @param string|float|int $qq 群号
	 * @param string $file 文件路径，必须是本地
	 * @param string $name 文件名称
	 * @return object
	 */
	public function uploadPrivateFile(string|float|int $qq, string $file, string $name) : object {
		$url = $this->api . '/upload_private_file';
		$data = ['user_id'=>$qq, 'file'=>$file, 'name'=>$name];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 上传群文件
	 * @param string|float|int $group 群号
	 * @param string $file 文件路径，必须是本地
	 * @param string $name 储存名称
	 * @param string $folder 父目录id，默认是根目录
	 * @return object
	 */
	public function uploadGroupFile(string|float|int $group, string $file, string $name, ?string $folder = null) : object {
		$url = $this->api . '/upload_group_file';
		$data = ['group_id'=>$group, 'file'=>$file, 'name'=>$name];
		if($folder) $data['folder'] = $folder;
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 删除群文件
	 * @param string|float|int $group 群号
	 * @param string $file_id 文件id
	 * @param int $busid 文件类型 [已废弃]
	 * @return object
	 */
	public function deleteGroupFile(string|float|int $group, string $file_id, int $busid = 0) : object {
		$url = $this->api . '/delete_group_file';
		$data = ['group_id'=>$group, 'file_id'=>$file_id];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 删除群文件夹
	 * @param string|float|int $group 群号
	 * @param string $file_id 文件id
	 * @param int $busid 文件类型 [已废弃]
	 * @return object
	 */
	public function deleteGroupFileFolder(string|float|int $group, string $folder_id, int $busid = 0) : object {
		$url = $this->api . '/delete_group_file_folder';
		$data = ['group_id'=>$group, 'folder_id'=>$folder_id];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 新建群文件的文件夹
	 * @param string|float|int $group 群号
	 * @param string $name 文件夹名字
	 * @return object
	 */
	public function createGroupFileFolder(string|float|int $group, string $name) : object {
		$url = $this->api . '/create_group_file_folder';
		$data = ['group_id'=>$group, 'name'=>$name, 'parent_id'=>'/'];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 重命名群文件的文件夹
	 * @param string|float|int $group 群号
	 * @param string $folder_id 文件夹id
	 * @param string $name 新文件夹名字
	 * @return object
	 */
	public function renameGroupFileFolder(string|float|int $group, string $folder_id, string $name) : object {
		$url = $this->api . '/rename_group_file_folder';
		$data = ['group_id'=>$group, 'folder_id' => $folder_id, 'new_folder_name'=>$name];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 删除群文件的文件夹
	 * @param string|float|int $group 群号
	 * @param string $folder_id 文件夹id
	 * @return object
	 */
	public function deleteGroupFolder(string|float|int $group, string $folder_id) : object {
		$url = $this->api . '/delete_group_folder';
		$data = ['group_id'=>$group, 'folder_id'=>$folder_id];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取群文件系统信息
	 * @param string|float|int $group 群号
	 * @return object
	 */
	public function getGroupFileSystemInfo(string|float|int $group) : object {
		$url = $this->api . '/get_group_file_system_info';
		$data = ['group_id'=>$group];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取群文件根目录文件列表
	 * @param string|float|int $group 群号
	 * @return object
	 */
	public function getGroupRootFiles(string|float|int $group) : object {
		$url = $this->api . '/get_group_root_files';
		$data = ['group_id'=>$group];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取群文件子目录文件列表
	 * @param string|float|int $group 群号
	 * @param string $folder_id 文件夹id，默认/
	 * @return object
	 */
	public function getGroupRootFilesByFolder(string|float|int $group, string $folder_id = '/') : object {
		$url = $this->api . '/get_group_files_by_folder';
		$data = ['group_id'=>$group, 'folder_id'=>$folder_id];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取群文件资源链接
	 * @param string|float|int $group 群号
	 * @param string $file_id 文件id
	 * @param int $busid 文件类型 [已废弃]
	 * @return object
	 */
	public function getGroupFileUrl(string|float|int $group, string $file_id, int $busid = 0) : object {
		$url = $this->api . '/get_group_file_url';
		$data = ['group_id'=>$group, 'file_id'=>$file_id];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 移动群文件
	 * @param string|float|int $group 群号
	 * @param string $file_id 文件id
	 * @param string $parent_directory 当前文件夹 id
	 * @param string $target_directory 目标文件夹 id
	 * @return object
	 */
	public function moveGroupFile(string|float|int $group, string $file_id, string $parent, string $target) : object {
		$url = $this->api . '/move_group_file';
		$data = ['group_id'=>$group, 'file_id'=>$file_id, 'parent_directory' => $parent, 'target_directory' => $target];
		return $this->post($url, $data);
	}
	
	/**
	 * @access public
	 * @desc 获取私聊文件资源链接
	 * @param string|float|int $group 群号
	 * @param string $file_id 文件id
	 * @param string $file_hash 文件 hash，可选
	 * @return object
	 */
	public function getPrivateFileUrl(string|float|int $qq, string $file_id, ?string $file_hash = null) : object {
		$url = $this->api . '/get_private_file_url';
		$data = ['user_id'=>$qq, 'file_id'=>$file_id, 'file_hash'=>$file_hash];
		return $this->post($url, $data);
	}
}